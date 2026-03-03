<?php
declare(strict_types=1);

function require_login(): void
{
    // Adjust if your login stores session differently
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        redirect('login.php');
    }
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_company_id(): int
{
    return (int)($_SESSION['company_id'] ?? 0);
}