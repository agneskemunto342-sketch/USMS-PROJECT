<?php
session_start();
require 'db.php';
$db = getDB();

// ---------- Helpers ----------
function gradeFromScore($score) {
    if ($score >= 70) return 'A';
    if ($score >= 60) return 'B';
    if ($score >= 50) return 'C';
    if ($score >= 40) return 'D';
    return 'E';
}
function gradePoint($score) {
    if ($score >= 70) return 4.0;
    if ($score >= 60) return 3.0;
    if ($score >= 50) return 2.0;
    if ($score >= 40) return 1.0;
    return 0.0;
}
function requireRole($role) {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== $role) {
        die("<div style='padding:20px;color:red;font-family:sans-serif;'>Access denied. <a href='?page=login'>Login</a></div>");
    }
}

// ---------- Handle Actions ----------
$action = $_POST['action'] ?? null;

if ($action === 'login') {
    $stmt = $db->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$_POST['username']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($u && password_verify($_POST['password'], $u['password_hash'])) {
        $_SESSION['user'] = $u;
        header("Location: ?page=dashboard"); 
        exit;
    } else {
        $error = "Invalid credentials.";
    }
}

if ($action === 'logout') { 
    session_destroy(); 
    header("Location: ?page=login"); 
    exit; 
}

if ($action === 'add_student') {
    requireRole('admin');
    $pw = password_hash('password', PASSWORD_BCRYPT);
    
    // Create User
    $db->prepare("INSERT INTO users(username,password_hash,role,full_name) VALUES(?,?,?,?)")
       ->execute([$_POST['reg_number'], $pw, 'student', $_POST['full_name']]);
    $uid = $db->lastInsertId();
    
    // Create Student Profile
    $db->prepare("INSERT INTO students(user_id,reg_number,course,year) VALUES(?,?,?,?)")
       ->execute([$uid, $_POST['reg_number'], $_POST['course'], $_POST['year']]);
    $sid = $db->lastInsertId();
    
    // Create Financial Record
    $db->prepare("INSERT INTO financials(student_id,total_fees,amount_paid) VALUES(?,?,0)")
       ->execute([$sid, $_POST['fees']]);
       
    header("Location: ?page=admin"); 
    exit;
}

if ($action === 'register_course') {
    requireRole('student');
    $s = $db->prepare("SELECT id FROM students WHERE user_id=?");
    $s->execute([$_SESSION['user']['id']]);
    $sid = $s->fetchColumn();
    
    try {
        $db->prepare("INSERT INTO registrations(student_id,course_id,semester) VALUES(?,?,?)")
           ->execute([$sid, $_POST['course_id'], $_POST['semester']]);
    } catch (Exception $e) { 
        // Ignore duplicate registrations
    }
    header("Location: ?page=dashboard"); 
    exit;
}

if ($action === 'save_marks') {
    requireRole('lecturer');
    
    // Check if marks already exist for this student and course
    $check = $db->prepare("SELECT id FROM marks WHERE student_id=? AND course_id=?");
    $check->execute([$_POST['student_id'], $_POST['course_id']]);
    $existing = $check->fetchColumn();

    if ($existing) {
        // Update existing marks
        $db->prepare("UPDATE marks SET cat_score=?, exam_score=? WHERE id=?")
           ->execute([$_POST['cat'], $_POST['exam'], $existing]);
    } else {
        // Insert new marks
        $db->prepare("INSERT INTO marks(student_id,course_id,cat_score,exam_score) VALUES(?,?,?,?)")
           ->execute([$_POST['student_id'], $_POST['course_id'], $_POST['cat'], $_POST['exam']]);
    }
    header("Location: ?page=lecturer"); 
    exit;
}

if ($action === 'pay_fees') {
    requireRole('admin');
    $db->prepare("UPDATE financials SET amount_paid = amount_paid + ? WHERE student_id=?")
       ->execute([$_POST['amount'], $_POST['student_id']]);
    header("Location: ?page=admin"); 
    exit;
}

// ---------- Routing ----------
$page = $_GET['page'] ?? 'login';
?>
<!DOCTYPE html>
<html>
<head>
    <title>IUSMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; color:#333; }
        nav { background:#1e3a8a; color:#fff; padding:12px 20px; display:flex; align-items:center; gap:15px; }
        nav strong { font-size:18px; }
        nav a { color:#fff; text-decoration:none; }
        nav a:hover { text-decoration:underline; }
        nav form { margin-left:auto; margin-bottom:0; }
        nav button { background:#ef4444; color:white; border:none; padding:6px 12px; border-radius:3px; cursor:pointer; }
        .container { max-width:900px; margin:20px auto; background:#fff; padding:25px; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,.1); }
        h2, h3 { color:#1e3a8a; margin-top:0; }
        input, select, button { padding:8px; margin:4px 0; font-size:14px; border:1px solid #ccc; border-radius:3px; }
        .btn { background:#2563eb; color:#fff; border:none; border-radius:3px; cursor:pointer; padding:8px 15px; text-decoration:none; display:inline-block;}
        .btn:hover { background:#1e40af; }
        table { width:100%; border-collapse:collapse; margin:15px 0; }
        th, td { border:1px solid #ddd; padding:10px; text-align:left; }
        th { background:#e0e7ff; }
        label { display:block; margin-top:10px; font-weight:bold; }
        .error { color:#b91c1c; background:#fee2e2; padding:10px; border-radius:3px; }
        .hint { font-size:13px; color:#555; margin-top:20px; padding:12px; background:#fef3c7; border-left:4px solid #f59e0b; }
        .transcript { border:2px solid #333; padding:30px; margin-top:20px; }
        @media print { nav, .btn, button { display:none; } .container { box-shadow:none; margin:0; padding:0; } .transcript { border:none; } }
    </style>
</head>
<body>

<?php if (isset($_SESSION['user'])): ?>
<nav>
    <strong>IUSMS</strong>
    <span>| <?= htmlspecialchars($_SESSION['user']['full_name']) ?> (<?= ucfirst($_SESSION['user']['role']) ?>)</span>
    <a href="?page=dashboard">Dashboard</a>
    <?php if ($_SESSION['user']['role']==='admin'): ?><a href="?page=admin">Admin</a><?php endif; ?>
    <?php if ($_SESSION['user']['role']==='lecturer'): ?><a href="?page=lecturer">Lecturer</a><?php endif; ?>
    <form method="post"><input type="hidden" name="action" value="logout"><button>Logout</button></form>
</nav>
<?php endif; ?>

<div class="container">

<?php if ($page === 'login'): ?>
    <h2>IUSMS Login</h2>
    <?php if (!empty($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <label>Username <input name="username" required autofocus></label>
        <label>Password <input type="password" name="password" required></label>
        <button class="btn">Login</button>
    </form>
    <div class="hint">
        <b>Demo Accounts (Password: <code>password</code>):</b><br>
        Admin: <code>admin</code><br>
        Lecturer: <code>lecturer</code><br>
        Student: <code>sc211001</code>
    </div>

<?php elseif ($page === 'dashboard' && isset($_SESSION['user'])):
    $u = $_SESSION['user'];
    
    if ($u['role'] === 'student'):
        $s = $db->prepare("SELECT * FROM students WHERE user_id=?"); 
        $s->execute([$u['id']]);
        $stu = $s->fetch(PDO::FETCH_ASSOC);
        
        if (!$stu) die("Student profile not found for this user.");

        $courses = $db->query("SELECT * FROM courses")->fetchAll(PDO::FETCH_ASSOC);
        $reg = $db->prepare("SELECT c.* FROM registrations r JOIN courses c ON c.id=r.course_id WHERE r.student_id=?");
        $reg->execute([$stu['id']]);
        $mycourses = $reg->fetchAll(PDO::FETCH_ASSOC);

        $fin = $db->prepare("SELECT * FROM financials WHERE student_id=?"); 
        $fin->execute([$stu['id']]);
        $f = $fin->fetch(PDO::FETCH_ASSOC);
        ?>
        <h2>Welcome, <?= htmlspecialchars($u['full_name']) ?></h2>
        <p><b>Reg No:</b> <?= htmlspecialchars($stu['reg_number']) ?> | <b>Course:</b> <?= htmlspecialchars($stu['course']) ?> | <b>Year:</b> <?= $stu['year'] ?></p>

        <h3>My Registered Units</h3>
        <ul>
        <?php if(empty($mycourses)): echo "<li>No units registered yet.</li>"; endif; ?>
        <?php foreach ($mycourses as $c): ?>
            <li><?= $c['code'] ?> – <?= htmlspecialchars($c['name']) ?> (<?= $c['credits'] ?> credits)</li>
        <?php endforeach; ?>
        </ul>

        <h3>Register a Unit</h3>
        <form method="post">
            <input type="hidden" name="action" value="register_course">
            <select name="course_id">
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= $c['code'] ?> - <?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="semester">
                <option>Sem1 2025</option>
                <option>Sem2 2025</option>
            </select>
            <button class="btn">Register</button>
        </form>

        <h3>Fee Balance</h3>
        <?php if($f): ?>
            <p>Total: KES <?= number_format($f['total_fees']) ?> | Paid: KES <?= number_format($f['amount_paid']) ?> | 
               <b>Balance: KES <?= number_format($f['total_fees'] - $f['amount_paid']) ?></b></p>
        <?php else: ?>
            <p>No financial record found.</p>
        <?php endif; ?>

        <h3>My Results</h3>
        <?php
        $m = $db->prepare("SELECT m.*, c.code, c.name, c.credits FROM marks m JOIN courses c ON c.id=m.course_id WHERE m.student_id=?");
        $m->execute([$stu['id']]);
        $rows = $m->fetchAll(PDO::FETCH_ASSOC);
        $totalPoints = 0; $totalCredits = 0;
        ?>
        <table>
            <tr><th>Code</th><th>Unit</th><th>CAT</th><th>Exam</th><th>Total</th><th>Grade</th></tr>
            <?php if(empty($rows)): ?><tr><td colspan="6">No results available yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r):
                $total = $r['cat_score'] + $r['exam_score'];
                $g = gradeFromScore($total);
                $totalPoints += gradePoint($total) * $r['credits'];
                $totalCredits += $r['credits'];
            ?>
            <tr>
                <td><?= $r['code'] ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= $r['cat_score'] ?></td>
                <td><?= $r['exam_score'] ?></td>
                <td><?= $total ?></td>
                <td><b><?= $g ?></b></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($totalCredits > 0): ?>
            <p><b>GPA:</b> <?= number_format($totalPoints / $totalCredits, 2) ?></p>
        <?php endif; ?>
        <p><a href="?page=transcript" class="btn">View / Print Transcript</a></p>

    <?php elseif ($u['role'] === 'lecturer'): ?>
        <h2>Lecturer Panel</h2>
        <h3>Enter or Update Marks</h3>
        <?php
        $students = $db->query("SELECT s.id, s.reg_number, u.full_name FROM students s JOIN users u ON u.id=s.user_id")->fetchAll(PDO::FETCH_ASSOC);
        $courses = $db->query("SELECT * FROM courses")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <form method="post">
            <input type="hidden" name="action" value="save_marks">
            <select name="student_id">
                <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['reg_number'] ?> - <?= htmlspecialchars($s['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="course_id">
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= $c['code'] ?></option>
                <?php endforeach; ?>
            </select>
            <input name="cat" type="number" step="0.5" max="30" placeholder="CAT /30" required>
            <input name="exam" type="number" step="0.5" max="70" placeholder="Exam /70" required>
            <button class="btn">Save Marks</button>
        </form>

        <h3>All Recorded Marks</h3>
        <?php
        $m = $db->query("SELECT m.*, c.code, s.reg_number FROM marks m
                         JOIN courses c ON c.id=m.course_id
                         JOIN students s ON s.id=m.student_id
                         ORDER BY s.reg_number, c.code")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table>
            <tr><th>Student</th><th>Course</th><th>CAT</th><th>Exam</th><th>Total</th><th>Grade</th></tr>
            <?php if(empty($m)): ?><tr><td colspan="6">No marks entered yet.</td></tr><?php endif; ?>
            <?php foreach ($m as $r): $t=$r['cat_score']+$r['exam_score']; ?>
                <tr>
                    <td><?= $r['reg_number'] ?></td>
                    <td><?= $r['code'] ?></td>
                    <td><?= $r['cat_score'] ?></td>
                    <td><?= $r['exam_score'] ?></td>
                    <td><?= $t ?></td>
                    <td><b><?= gradeFromScore($t) ?></b></td>
                </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($u['role'] === 'admin'): ?>
        <h2>Admin Dashboard</h2>
        <p><a href="?page=admin" class="btn">Manage Students & Fees</a></p>
    <?php endif; ?>

<?php elseif ($page === 'admin'):
    requireRole('admin');
    // Fixed: Joined users table to get the full_name
    $students = $db->query("
        SELECT s.*, u.full_name, f.total_fees, f.amount_paid 
        FROM students s 
        JOIN users u ON u.id = s.user_id
        JOIN financials f ON f.student_id = s.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h2>Student Management</h2>
    <h3>Add New Student</h3>
    <form method="post" style="background:#f9fafb; padding:15px; border-radius:5px; margin-bottom:20px;">
        <input type="hidden" name="action" value="add_student">
        <input name="reg_number" placeholder="Reg Number (e.g. SC211/0002/24)" required>
        <input name="full_name" placeholder="Full Name" required>
        <input name="course" placeholder="Course Name" required>
        <input name="year" type="number" placeholder="Year (1-4)" min="1" max="4" required>
        <input name="fees" type="number" placeholder="Total Fees (KES)" required>
        <button class="btn">Add Student</button>
    </form>

    <h3>All Students & Fee Balances</h3>
    <table>
        <tr><th>Reg No</th><th>Name</th><th>Course</th><th>Fee Balance</th><th>Action</th></tr>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['reg_number']) ?></td>
                <td><?= htmlspecialchars($s['full_name']) ?></td>
                <td><?= htmlspecialchars($s['course']) ?></td>
                <td>KES <?= number_format($s['total_fees'] - $s['amount_paid']) ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="pay_fees">
                        <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                        <input name="amount" type="number" placeholder="Amount" style="width:80px;" required>
                        <button class="btn" style="padding:4px 8px; font-size:12px;">Pay</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php elseif ($page === 'lecturer'):
    requireRole('lecturer');
    header("Location: ?page=dashboard"); 
    exit;

 elseif ($page === 'transcript'):
    requireRole('student');
    $u = $_SESSION['user'];
    $s = $db->prepare("SELECT * FROM students WHERE user_id=?"); 
    $s->execute([$u['id']]);
    $stu = $s->fetch(PDO::FETCH_ASSOC);
    
    $m = $db->prepare("SELECT m.*, c.code, c.name, c.credits FROM marks m JOIN courses c ON c.id=m.course_id WHERE m.student_id=?");
    $m->execute([$stu['id']]);
    $rows = $m->fetchAll(PDO::FETCH_ASSOC);
    $pts=0; $cr=0;
    ?>
    <div class="transcript">
        <h2 style="text-align:center;">Official Academic Transcript</h2>
        <p><b>Name:</b> <?= htmlspecialchars($u['full_name']) ?><br>
           <b>Reg No:</b> <?= htmlspecialchars($stu['reg_number']) ?><br>
           <b>Course:</b> <?= htmlspecialchars($stu['course']) ?></p>
        <table>
            <tr><th>Code</th><th>Unit</th><th>Credits</th><th>Total</th><th>Grade</th><th>Points</th></tr>
            <?php foreach ($rows as $r):
                $t = $r['cat_score'] + $r['exam_score'];
                $gp = gradePoint($t);
                $pts += $gp * $r['credits']; 
                $cr += $r['credits'];
            ?>
            <tr>
                <td><?= $r['code'] ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= $r['credits'] ?></td>
                <td><?= $t ?></td>
                <td><?= gradeFromScore($t) ?></td>
                <td><?= $gp ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($cr): ?>
            <p><b>Cumulative GPA:</b> <?= number_format($pts/$cr, 2) ?></p>
        <?php endif; ?>
        <button class="btn" onclick="window.print()">Print Transcript</button>
    </div>

<?php else: ?>
    <p>Page not found. <a href="?page=login">Return to Login</a></p>
<?php endif; ?>

</div>
</body>
</html>