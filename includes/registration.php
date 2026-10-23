<?php
/**
 * includes/registration.php
 * The rules for registering a guardian and their children, shared by:
 *   - student_new.php  (staff registering one student)
 *   - register.php     (parents registering one or more children)
 * so both places validate and save in exactly the same way.
 */

require_once __DIR__ . '/auth.php';

const RELATIONS = ['Mother', 'Father', 'Guardian', 'Grandparent', 'Aunt', 'Uncle', 'Sibling', 'Other'];

/**
 * Check one child's details. Problems are added to $errors (prefixed, e.g. "Child 2: ...").
 * Returns the cleaned values: full_name, dob (Y-m-d), nationality, id_no ('' if none).
 */
function validate_child(array $in, array &$errors, string $prefix = ''): array
{
    $name = trim((string) ($in['full_name'] ?? ''));
    $nat  = trim((string) ($in['nationality'] ?? ''));
    $idNo = normalize_id_number((string) ($in['id_passport_no'] ?? ''));
    $dobRaw = trim((string) ($in['date_of_birth'] ?? ''));

    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = $prefix . 'Enter the full name (up to 120 characters).';
    }

    $dobOut = '';
    $dob = parse_date($dobRaw);
    if ($dob === null) {
        $errors[] = $prefix . 'Enter a valid date of birth.';
    } elseif ($dob > new DateTimeImmutable('today')) {
        $errors[] = $prefix . 'The date of birth cannot be in the future.';
    } elseif (age_years($dob->format('Y-m-d')) > 25) {
        $errors[] = $prefix . 'The date of birth makes the student older than 25. Please check it.';
    } else {
        $dobOut = $dob->format('Y-m-d');
    }

    if ($nat === '' || mb_strlen($nat) > 60) {
        $errors[] = $prefix . 'Enter the nationality (up to 60 characters).';
    }

    if ($idNo !== '' && !valid_id_number($idNo)) {
        $errors[] = $prefix . 'The ID/passport number may only contain letters, digits, - and / (4 to 30 characters). Leave it blank if there is none.';
    }

    return ['full_name' => $name, 'dob' => $dobOut, 'nationality' => $nat, 'id_no' => $idNo];
}

/**
 * Check the guardian's details. Returns id_number, full_name, phone, alt_phone and
 * 'existing' (the saved guardian row with this ID number, or null for a new guardian).
 * The existing-guardian lookup is done on the server; we never trust the browser for it.
 */
function validate_guardian(array $in, array &$errors): array
{
    $idNo  = normalize_id_number((string) ($in['id_number'] ?? ''));
    $name  = trim((string) ($in['full_name'] ?? ''));
    $phone = normalize_phone((string) ($in['phone'] ?? ''));
    $altRaw = trim((string) ($in['alt_phone'] ?? ''));
    $alt   = null;

    if (!valid_id_number($idNo)) {
        $errors[] = 'Enter the guardian\'s ID number (letters, digits, - and / only, 4 to 30 characters).';
    }
    if ($phone === null) {
        $errors[] = 'Enter a valid phone number, e.g. 0712 345 678. Numbers from other countries must start with +.';
    }
    if ($altRaw !== '') {
        $alt = normalize_phone($altRaw);
        if ($alt === null) {
            $errors[] = 'The alternative phone number is not valid. Leave it blank if there is none.';
        }
    }

    $existing = null;
    if (valid_id_number($idNo)) {
        $stmt = db()->prepare('SELECT id, full_name, phone FROM guardians WHERE id_number = ?');
        $stmt->execute([$idNo]);
        $existing = $stmt->fetch() ?: null;
    }

    if ($existing === null && ($name === '' || mb_strlen($name) > 120)) {
        $errors[] = 'Enter the guardian\'s full name (up to 120 characters).';
    }

    return ['id_number' => $idNo, 'full_name' => $name, 'phone' => $phone, 'alt_phone' => $alt, 'existing' => $existing];
}

/** Is this ID/passport number already registered to another student? Returns that student's name or null. */
function student_id_taken(string $idNo): ?string
{
    if ($idNo === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT full_name FROM students WHERE id_passport_no = ?');
    $stmt->execute([$idNo]);
    $name = $stmt->fetchColumn();
    return $name === false ? null : (string) $name;
}

/** The id of a student with this name and date of birth under this guardian, or null. */
function find_duplicate_child(int $guardianId, string $name, string $dob): ?int
{
    $stmt = db()->prepare('SELECT id FROM students WHERE guardian_id = ? AND full_name = ? AND date_of_birth = ?');
    $stmt->execute([$guardianId, $name, $dob]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Save the guardian (call inside a transaction) and return their id.
 * An existing guardian keeps their saved name. Staff may correct phone numbers ($updatePhones = true);
 * the public form never changes a stored guardian, otherwise anyone could overwrite someone else's phone.
 */
function save_guardian(PDO $pdo, array $g, bool $updatePhones): int
{
    if ($g['existing'] !== null) {
        $id = (int) $g['existing']['id'];
        if ($updatePhones) {
            $pdo->prepare('UPDATE guardians SET phone = ?, alt_phone = ? WHERE id = ?')
                ->execute([$g['phone'], $g['alt_phone'], $id]);
        }
        return $id;
    }

    $pdo->prepare('INSERT INTO guardians (full_name, id_number, phone, alt_phone) VALUES (?, ?, ?, ?)')
        ->execute([$g['full_name'], $g['id_number'], $g['phone'], $g['alt_phone']]);
    return (int) $pdo->lastInsertId();
}

/**
 * Save one student (call inside a transaction) together with their one-time registration charge.
 * The fee is copied onto the charge, so changing REGISTRATION_FEE later never alters existing students.
 */
function save_child(PDO $pdo, int $guardianId, array $child, string $relation, ?int $registeredBy): int
{
    $pdo->prepare(
        'INSERT INTO students
            (full_name, date_of_birth, nationality, id_passport_no, guardian_id, relation, registered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $child['full_name'],
        $child['dob'],
        $child['nationality'],
        $child['id_no'] !== '' ? $child['id_no'] : null,      // NULL, not an empty string, when blank
        $guardianId,
        $relation,
        $registeredBy,
    ]);
    $studentId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO charges (student_id, enrollment_id, billing_period_id, charge_type, amount)
         VALUES (?, NULL, NULL, 'REGISTRATION', ?)"
    )->execute([$studentId, REGISTRATION_FEE]);

    return $studentId;
}
