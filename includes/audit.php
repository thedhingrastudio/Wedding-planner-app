<?php
declare(strict_types=1);

if (!function_exists('audit_client_ip')) {
  function audit_client_ip(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') $ip = 'unknown';
    return substr($ip, 0, 45);
  }
}

if (!function_exists('audit_user_agent')) {
  function audit_user_agent(): string {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return substr($ua, 0, 255);
  }
}

if (!function_exists('audit_actor_user_id')) {
  function audit_actor_user_id(): ?int {
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('audit_actor_name')) {
  function audit_actor_name(): string {
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    return $name !== '' ? $name : 'Unknown member';
  }
}

if (!function_exists('audit_json_string')) {
  function audit_json_string($value): ?string {
    if ($value === null) return null;
    if (is_string($value)) return $value;

    try {
      $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      return $json === false ? null : $json;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('audit_build_search_text')) {
  function audit_build_search_text(array $parts): string {
    $out = [];

    foreach ($parts as $part) {
      if (is_array($part)) {
        foreach ($part as $sub) {
          $sub = trim((string)$sub);
          if ($sub !== '') $out[] = $sub;
        }
        continue;
      }

      $part = trim((string)$part);
      if ($part !== '') $out[] = $part;
    }

    return implode(' | ', $out);
  }
}

if (!function_exists('audit_log')) {
  function audit_log(array $payload): void {
    try {
      $pdo = ($payload['pdo'] ?? null);
      if (!$pdo instanceof PDO) $pdo = get_pdo();

      $companyId = (int)($payload['company_id'] ?? 0);
      if ($companyId <= 0) return;

      $projectId = isset($payload['project_id']) && (int)$payload['project_id'] > 0
        ? (int)$payload['project_id']
        : null;

      $actorUserId = isset($payload['actor_user_id']) && (int)$payload['actor_user_id'] > 0
        ? (int)$payload['actor_user_id']
        : null;

      $targetCompanyMemberId = isset($payload['target_company_member_id']) && (int)$payload['target_company_member_id'] > 0
        ? (int)$payload['target_company_member_id']
        : null;

      $entityType = trim((string)($payload['entity_type'] ?? 'system'));
      $entityId = isset($payload['entity_id']) && (int)$payload['entity_id'] > 0
        ? (int)$payload['entity_id']
        : null;

      $action = trim((string)($payload['action'] ?? 'updated'));
      $summary = trim((string)($payload['summary'] ?? 'Updated record'));
      $actorName = trim((string)($payload['actor_name'] ?? audit_actor_name()));
      if ($actorName === '') $actorName = 'Unknown member';

      $diffJson = audit_json_string($payload['diff_json'] ?? null);
      $searchText = trim((string)($payload['search_text'] ?? ''));
      if ($searchText === '') {
        $searchText = audit_build_search_text([
          $actorName,
          $entityType,
          $action,
          $summary,
        ]);
      }

      $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
          company_id,
          project_id,
          actor_user_id,
          actor_name,
          target_company_member_id,
          entity_type,
          entity_id,
          action,
          summary,
          ip_address,
          user_agent,
          diff_json,
          search_text,
          created_at
        ) VALUES (
          :company_id,
          :project_id,
          :actor_user_id,
          :actor_name,
          :target_company_member_id,
          :entity_type,
          :entity_id,
          :action,
          :summary,
          :ip_address,
          :user_agent,
          :diff_json,
          :search_text,
          NOW()
        )
      ");

      $stmt->execute([
        ':company_id' => $companyId,
        ':project_id' => $projectId,
        ':actor_user_id' => $actorUserId,
        ':actor_name' => $actorName,
        ':target_company_member_id' => $targetCompanyMemberId,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':action' => $action,
        ':summary' => $summary,
        ':ip_address' => audit_client_ip(),
        ':user_agent' => audit_user_agent(),
        ':diff_json' => $diffJson,
        ':search_text' => $searchText,
      ]);
    } catch (Throwable $e) {
      // v0: never break the main user flow if audit insert fails
    }
  }
}