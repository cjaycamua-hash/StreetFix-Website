<?php
require __DIR__ . '/../../config/config.php';
$in = post_only();

$s = fn(string $k): string => trim((string)($in[$k] ?? ''));
$first = $s('first_name');   $middle = $s('middle_name');  $last = $s('last_name');
$first = format_person_name($first); $middle = format_person_name($middle); $last = format_person_name($last);
$ext   = $s('name_extension'); $email = strtolower($s('email'));
$birth = $s('birthdate');    $contact = preg_replace('/[\s-]/', '', $s('contact_number'));
$addr  = $s('address');      $pass = (string)($in['password'] ?? ''); $conf = (string)($in['confirm'] ?? '');

$errors = [];
if ($first === '' || mb_strlen($first) > 60) $errors[] = 'First name is required (max 60 characters).';
if ($last === ''  || mb_strlen($last) > 60)  $errors[] = 'Last name is required (max 60 characters).';
if (mb_strlen($middle) > 60) $errors[] = 'Middle name is too long.';
if (!in_array($ext, ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'], true)) $errors[] = 'Invalid name extension.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid email address.';

$d = DateTime::createFromFormat('Y-m-d', $birth);
if (!$d || $d->format('Y-m-d') !== $birth || $d > new DateTime('today')) {
    $errors[] = 'Enter a valid birthdate.';
    $age = 0;
} else {
    $age = (int)$d->diff(new DateTime('today'))->y;   // age is computed from the birthdate
    if ($age < 13 || $age > 120) $errors[] = 'You must be at least 13 years old to register.';
}
if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $contact)) $errors[] = 'Contact number must look like 09171234567 or +639171234567.';
if ($addr === '' || mb_strlen($addr) > 255) $errors[] = 'Address is required (max 255 characters).';
if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
if (mb_strlen($pass) > 255) $errors[] = 'Password must be no more than 255 characters.';
if ($pass !== $conf)   $errors[] = 'Passwords do not match.';

if ($errors) respond(['ok' => false, 'error' => implode(' ', $errors)], 422);

$pdo = db();
$chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
$chk->execute([$email]);
if ($chk->fetch()) respond(['ok' => false, 'error' => 'That email is already registered. Try logging in.'], 409);

$ins = $pdo->prepare('INSERT INTO users
  (first_name, middle_name, last_name, name_extension, email, age, birthdate, contact_number, address, password)
  VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute([$first, $middle ?: null, $last, $ext ?: null, $email, $age, $birth, $contact, $addr, $pass]);

// No auto-login: send the new user to the login page to sign in.
respond(['ok' => true, 'redirect' => 'login.php?registered=1'], 201);
