<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

function prompt(string $label): string {
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : rtrim($value, "\r\n");
}

$email = trim(prompt('Admin email: '));
$name = trim(prompt('Admin name: '));
$password = prompt('Admin password: ');
$passwordLength = preg_match_all('/./us', $password, $passwordCharacters);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "A valid email and a name are required.\n");
    exit(1);
}

if ($passwordLength === false || $passwordLength < 10) {
    fwrite(STDERR, "The password must be at least 10 characters long.\n");
    exit(1);
}

try {
    $pdo = db();
    $existingAdmin = $pdo->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
    $existingAdmin->execute(['email' => $email]);
    if ($existingAdmin->fetchColumn() !== false) {
        fwrite(STDERR, "An admin with that email already exists.\n");
        exit(1);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $insertAdmin = $pdo->prepare(
        'INSERT INTO admins (email, name, password_hash) VALUES (:email, :name, :password_hash)'
    );
    $insertAdmin->execute([
        'email' => $email,
        'name' => $name,
        'password_hash' => $passwordHash,
    ]);
    fwrite(STDOUT, "Admin account created.\n");
} catch (PDOException $exception) {
    $errorInfo = $exception->errorInfo ?? [];
    if (($errorInfo[1] ?? null) === 1062) {
        fwrite(STDERR, "An admin with that email already exists.\n");
        exit(1);
    }

    error_log('LCUPAY admin seed failed.');
    fwrite(STDERR, "Unable to create the admin account.\n");
    exit(1);
} catch (Throwable $exception) {
    error_log('LCUPAY admin seed failed.');
    fwrite(STDERR, "Unable to create the admin account.\n");
    exit(1);
}
