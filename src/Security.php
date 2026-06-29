<?php
/**
 * Security utilities for input validation, sanitization, and protection
 */
final class Security
{
    // Rate limiting cache
    private static array $rateLimitCache = [];

    /**
     * Validate and sanitize input based on type
     */
    public static function validate(mixed $input, string $type): mixed
    {
        return match ($type) {
            'email' => self::validateEmail($input),
            'url' => self::validateUrl($input),
            'slug' => self::validateSlug($input),
            'int' => self::validateInt($input),
            'filename' => self::validateFilename($input),
            'text' => self::sanitizeText($input),
            'json' => self::validateJson($input),
            default => $input,
        };
    }

    public static function validateEmail(mixed $input): string|false
    {
        $email = is_string($input) ? trim($input) : '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }

    public static function validateUrl(mixed $input): string|false
    {
        $url = is_string($input) ? trim($input) : '';
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
    }

    public static function validateSlug(mixed $input): string
    {
        $slug = is_string($input) ? trim($input) : '';
        return trim(preg_replace('/[^\p{Arabic}a-z0-9_-]+/iu', '-', mb_strtolower($slug)), '-') ?: 'item-' . time();
    }

    public static function validateInt(mixed $input): int|false
    {
        if (is_int($input)) {
            return $input;
        }
        $int = filter_var($input, FILTER_VALIDATE_INT);
        return $int !== false ? $int : false;
    }

    public static function validateFilename(mixed $input): string|false
    {
        $filename = is_string($input) ? basename(trim($input)) : '';
        // Only allow alphanumeric, dots, hyphens, underscores
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            return false;
        }
        // Prevent directory traversal
        if (str_contains($filename, '..') || str_contains($filename, '/')) {
            return false;
        }
        return $filename;
    }

    public static function sanitizeText(mixed $input): string
    {
        $text = is_string($input) ? $input : '';
        return trim(strip_tags($text));
    }

    public static function validateJson(mixed $input): array|false
    {
        if (is_array($input)) {
            return $input;
        }
        if (!is_string($input)) {
            return false;
        }
        $decoded = json_decode(trim($input), true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
    }

    /**
     * Check rate limit for an action (uses memory)
     * Returns true if limit exceeded
     */
    public static function checkRateLimit(string $identifier, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $now = time();
        $key = $identifier;

        if (!isset(self::$rateLimitCache[$key])) {
            self::$rateLimitCache[$key] = [];
        }

        // Remove old entries
        self::$rateLimitCache[$key] = array_filter(
            self::$rateLimitCache[$key],
            fn($timestamp) => $now - $timestamp < $windowSeconds
        );

        $count = count(self::$rateLimitCache[$key]);

        if ($count >= $maxRequests) {
            return true; // Rate limit exceeded
        }

        self::$rateLimitCache[$key][] = $now;
        return false; // Within limit
    }

    /**
     * Sanitize output for HTML context
     */
    public static function escapeHtml(mixed $input): string
    {
        return htmlspecialchars((string)$input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize output for JSON context
     */
    public static function escapeJson(mixed $input): string
    {
        return json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrf(string $token, string $sessionToken): bool
    {
        return hash_equals($token, $sessionToken);
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrf(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check if authenticated (basic implementation)
     */
    public static function isAuthenticated(string $token = ''): bool
    {
        // This can be extended with actual auth logic
        return !empty($token);
    }

    /**
     * Hash a password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Verify a password
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Sanitize array recursively
     */
    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Validate key
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string)$key)) {
                continue;
            }
            
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } else {
                $sanitized[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        return $sanitized;
    }
}
