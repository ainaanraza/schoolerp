<?php

function developer_portal_credentials(): array
{
    $username = trim((string)(getenv('DEVELOPER_PORTAL_USER') ?: 'devmaster'));
    $password = (string)(getenv('DEVELOPER_PORTAL_PASS') ?: 'DevPortal@123');

    return [
        'username' => $username !== '' ? $username : 'devmaster',
        'password' => $password !== '' ? $password : 'DevPortal@123',
    ];
}
