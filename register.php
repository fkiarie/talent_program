<?php
// register.php - Public Parent Registration Portal for MIST Talent Program
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$success_message = '';
$error_message = '';

// Fetch active activities with levels
$pdo = get_db();
$stmt = $pdo->prepare("
    SELECT a.id AS activity_id, a.name AS activity_name, al.id AS level_id, al.proficiency, al.monthly_fee 
    FROM activities a
    JOIN activity_levels al ON a.id = al.activity_id
    WHERE a.is_active = 1
    ORDER BY a.name, al.monthly_fee ASC
");
$stmt->execute();
$activities_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activities = [];
foreach ($activities_raw as $row) {
    $activities[$row['activity_id']]['name'] = $row['activity_name'];
    $activities[$row['activity_id']]['levels'][] = [
        'id' => $row['level_id'],
        'proficiency' => $row['proficiency'],
        'fee' => $row['monthly_fee']
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Guardian Details
        $g_name     = trim($_POST['guardian_name'] ?? '');
        $g_id       = trim($_POST['guardian_id_no'] ?? '');
        $g_phone    = trim($_POST['guardian_phone'] ?? '');
        $g_alt_phone= trim($_POST['guardian_alt_phone'] ?? '');
        $relation   = trim($_POST['relation'] ?? 'Parent');

        if (empty($g_name) || empty($g_id) || empty($g_phone)) {
            throw new Exception("Please fill in all required parent/guardian fields.");
        }

        // Check if guardian exists
        $stmt_g = $pdo->prepare("SELECT id FROM guardians WHERE id_number = ? OR phone = ?");
        $stmt_g->execute([$g_id, $g_phone]);
        $existing_guardian = $stmt_g->fetch(PDO::FETCH_ASSOC);

        if ($existing_guardian) {
            $guardian_id = $existing_guardian['id'];
        } else {
            $stmt_ins_g = $pdo->prepare("INSERT INTO guardians (full_name, id_number, phone, alt_phone) VALUES (?, ?, ?, ?)");
            $stmt_ins_g->execute([$g_name, $g_id, $g_phone, $g_alt_phone ?: null]);
            $guardian_id = $pdo->lastInsertId();
        }

        // 2. Student Details (Supports multiple children)
        $children_names = $_POST['child_name'] ?? [];
        $children_dobs  = $_POST['child_dob'] ?? [];
        $children_levels= $_POST['activity_level_id'] ?? [];

        if (empty($children_names)) {
            throw new Exception("At least one child registration is required.");
        }

        $registered_students = [];
        $total_reg_fee = 0;
        $total_activity_fee = 0;
        $registration_fee_per_child = 2000.00;

        for ($i = 0; $i < count($children_names); $i++) {
            $c_name  = trim($children_names[$i]);
            $c_dob   = trim($children_dobs[$i]);
            $c_level = intval($children_levels[$i] ?? 0);

            if (empty($c_name) || empty($c_dob)) continue;

            // Insert Student
            $stmt_s = $pdo->prepare("INSERT INTO students (full_name, date_of_birth, nationality, guardian_id, relation, registered_on, registered_by) VALUES (?, ?, 'Kenyan', ?, ?, CURDATE(), NULL)");
            $stmt_s->execute([$c_name, $c_dob, $guardian_id, $relation]);
            $student_id = $pdo->lastInsertId();

            // Insert Registration Charge (KSh 2,000)
            $stmt_c = $pdo->prepare("INSERT INTO charges (student_id, charge_type, amount, created_on) VALUES (?, 'REGISTRATION', ?, CURDATE())");
            $stmt_c->execute([$student_id, $registration_fee_per_child]);
            $total_reg_fee += $registration_fee_per_child;

            // Enroll in Activity if selected
            if ($c_level > 0) {
                // Get monthly fee for level
                $stmt_lvl = $pdo->prepare("SELECT monthly_fee FROM activity_levels WHERE id = ?");
                $stmt_lvl->execute([$c_level]);
                $lvl_data = $stmt_lvl->fetch(PDO::FETCH_ASSOC);

                if ($lvl_data) {
                    $m_fee = $lvl_data['monthly_fee'];
                    $stmt_e = $pdo->prepare("INSERT INTO enrollments (student_id, activity_level_id, monthly_fee, status, enrolled_on) VALUES (?, ?, ?, 'ACTIVE', CURDATE())");
                    $stmt_e->execute([$student_id, $c_level, $m_fee]);
                    $total_activity_fee += $m_fee;
                }
            }

            $registered_students[] = [
                'id' => $student_id,
                'name' => $c_name
            ];
        }

        $pdo->commit();
        $grand_total = $total_reg_fee + $total_activity_fee;
        
        // Redirect to demo payment landing page
        header("Location: pay_demo.php?guardian_id=" . $guardian_id . "&amount=" . $grand_total . "&phone=" . urlencode($g_phone));
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Registration | MIST Talent Program</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mistnavy: '#262262',
                        mistgold: '#FFC000',
                        mistgreen: '#6dad13',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen flex flex-col">

    <!-- Header / Branding Bar -->
    <header class="bg-mistnavy text-white shadow-md border-b-4 border-mistgold sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 py-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 bg-mistgold rounded-full flex items-center justify-center font-bold text-mistnavy text-xl shadow">
                    MIST
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-white">Musketeers Institute of Science and Technology</h1>
                    <p class="text-xs text-mistgold font-medium">Talent Program Registration Portal</p>
                </div>
            </div>
            <div class="text-right text-xs text-slate-200 hidden md:block">
                <p><i class="fas fa-phone text-mistgold mr-1"></i> +254 700 904 523</p>
                <p><i class="fas fa-envelope text-mistgold mr-1"></i> info@mist.ac.ke</p>
                <p><i class="fas fa-location-dot text-mistgold mr-1"></i> Mumbi Plaza, 4th Floor, Nakuru</p>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-4xl mx-auto px-4 py-8 flex-grow">
        
        <!-- Welcome Banner -->
        <div class="bg-gradient-to-r from-mistnavy to-indigo-900 rounded-2xl p-6 mb-8 text-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 opacity-10 text-white text-9xl">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <span class="bg-mistgreen text-white text-xs font-bold uppercase tracking-wider px-3 py-1 rounded-full mb-3 inline-block">Admissions Open</span>
            <h2 class="text-2xl md:text-3xl font-extrabold mb-2">Register Your Child for MIST Youth Talent Program</h2>
            <p class="text-slate-200 text-sm max-w-2xl">
                Empower your child with professional practical skills in sports, technology, and arts. Complete the form below to enroll.
            </p>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 mb-6 rounded-r shadow-sm flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-circle-exclamation text-rose-500 text-xl"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" class="space-y-8">
            
            <!-- STEP 1: Guardian / Parent Information -->
            <div class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
                <div class="flex items-center space-x-3 border-b border-slate-100 pb-4 mb-6">
                    <div class="w-8 h-8 rounded-lg bg-mistnavy text-mistgold flex items-center justify-center font-bold">1</div>
                    <h3 class="text-lg font-bold text-mistnavy">Parent / Guardian Information</h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Full Name <span class="text-rose-500">*</span></label>
                        <input type="text" name="guardian_name" required placeholder="e.g., Jane Doe" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-mistnavy focus:border-transparent text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">National ID / Passport Number <span class="text-rose-500">*</span></label>
                        <input type="text" name="guardian_id_no" required placeholder="e.g., 28475920" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-mistnavy focus:border-transparent text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Primary M-Pesa Phone Number <span class="text-rose-500">*</span></label>
                        <input type="tel" name="guardian_phone" required placeholder="0700904523 or 254700904523" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-mistnavy focus:border-transparent text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Relationship to Child <span class="text-rose-500">*</span></label>
                        <select name="relation" required class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-mistnavy focus:border-transparent text-sm">
                            <option value="Mother">Mother</option>
                            <option value="Father">Father</option>
                            <option value="Guardian">Guardian</option>
                            <option value="Sponsor">Sponsor</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Student Enrollment -->
            <div class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-lg bg-mistnavy text-mistgold flex items-center justify-center font-bold">2</div>
                        <h3 class="text-lg font-bold text-mistnavy">Child / Student Details</h3>
                    </div>
                    <button type="button" id="add-child-btn" class="bg-slate-100 hover:bg-slate-200 text-mistnavy text-xs font-bold px-3 py-2 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-plus text-mistgreen"></i> Add Another Child
                    </button>
                </div>

                <div id="children-container" class="space-y-6">
                    <!-- Child Entry Row 1 -->
                    <div class="child-row p-4 rounded-xl bg-slate-50 border border-slate-200 relative">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Child Full Name <span class="text-rose-500">*</span></label>
                                <input type="text" name="child_name[]" required placeholder="e.g., Alex Kimani" class="w-full px-3 py-2 rounded-md border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="child_dob[]" required class="w-full px-3 py-2 rounded-md border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Select Talent / Activity</label>
                                <select name="activity_level_id[]" class="w-full px-3 py-2 rounded-md border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm">
                                    <option value="0">-- Select Program --</option>
                                    <?php foreach ($activities as $act_id => $act): ?>
                                        <optgroup label="<?= htmlspecialchars($act['name']) ?>">
                                            <?php foreach ($act['levels'] as $lvl): ?>
                                                <option value="<?= $lvl['id'] ?>">
                                                    <?= htmlspecialchars($act['name']) ?> (<?= $lvl['proficiency'] ?>) - KSh <?= number_format($lvl['fee'], 2) ?>/mo
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 bg-amber-50 rounded-lg p-4 border border-amber-200 text-xs text-amber-900 flex items-start gap-3">
                    <i class="fas fa-info-circle text-mistgold text-base mt-0.5"></i>
                    <div>
                        <p class="font-bold">Standard Fees Notice:</p>
                        <p>A non-refundable standard registration fee of <strong>KSh 2,000</strong> is charged per student alongside chosen activity monthly tuition fees.</p>
                    </div>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                <div>
                    <p class="text-xs text-slate-500">By submitting, you agree to MIST Talent Program guidelines.</p>
                </div>
                <button type="submit" class="w-full sm:w-auto bg-mistgreen hover:bg-emerald-700 text-white font-bold px-8 py-3 rounded-xl shadow-lg transition flex items-center justify-center gap-2">
                    Proceed to Payment <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </form>
    </main>

    <footer class="bg-mistnavy text-slate-300 text-xs py-6 border-t border-mistgold">
        <div class="max-w-6xl mx-auto px-4 text-center space-y-2">
            <p>&copy; <?= date('Y') ?> Musketeers Institute of Science and Technology (MIST). All rights reserved.</p>
            <p>Mumbi Plaza, 4th Floor, Nakuru | Contact: +254 700 904 523 | Email: info@mist.ac.ke</p>
        </div>
    </footer>

    <script>
        document.getElementById('add-child-btn').addEventListener('click', function() {
            const container = document.getElementById('children-container');
            const firstRow = container.querySelector('.child-row');
            const newRow = firstRow.cloneNode(true);
            
            // Clear inputs
            newRow.querySelectorAll('input').forEach(input => input.value = '');
            newRow.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            
            // Add delete button
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'absolute -top-3 -right-3 bg-rose-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs shadow hover:bg-rose-600';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick = function() { newRow.remove(); };
            
            newRow.appendChild(removeBtn);
            container.appendChild(newRow);
        });
    </script>
</body>
</html>