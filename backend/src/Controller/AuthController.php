<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Jwt;
use PDO;

final class AuthController
{
    public function __construct(
        private readonly Database $db,
        private readonly string $jwtSecret,
    ) {
    }

    public function register(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $login = trim((string) ($b['login'] ?? ''));
        $email = trim((string) ($b['email'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        if ($login === '' || $email === '' || strlen($password) < 6) {
            return Response::json(['error' => 'validation', 'message' => 'login, email и пароль (min 6) обязательны'], 422);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo = $this->db->pdo();
        try {
            $st = $pdo->prepare('INSERT INTO users (login, email, password_hash, role) VALUES (?,?,?,?)');
            $st->execute([$login, $email, $hash, 'client']);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return Response::json(['error' => 'duplicate', 'message' => 'Логин или email заняты'], 409);
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        $token = Jwt::encode(['sub' => $id, 'role' => 'client'], $this->jwtSecret);
        return Response::json(['token' => $token, 'user' => ['id' => $id, 'login' => $login, 'email' => $email, 'role' => 'client']], 201);
    }

    public function login(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $login = trim((string) ($b['login'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        if ($login === '' || $password === '') {
            return Response::json(['error' => 'validation'], 422);
        }
        $st = $this->db->pdo()->prepare('SELECT id, login, email, password_hash, role FROM users WHERE login = ? LIMIT 1');
        $st->execute([$login]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return Response::json(['error' => 'invalid_credentials'], 401);
        }
        $role = $row['role'] === 'moderator' ? 'moderator' : 'client';
        $token = Jwt::encode(['sub' => (int) $row['id'], 'role' => $role], $this->jwtSecret);
        return Response::json([
            'token' => $token,
            'user' => [
                'id' => (int) $row['id'],
                'login' => $row['login'],
                'email' => $row['email'],
                'role' => $role,
            ],
        ]);
    }

    public function me(Request $request): Response
    {
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $st = $this->db->pdo()->prepare('SELECT id, login, email, role FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['user' => [
            'id' => (int) $row['id'],
            'login' => $row['login'],
            'email' => $row['email'],
            'role' => $row['role'],
        ]]);
    }
}
