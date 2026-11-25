<?php
function app_task_deadline_at(?string $deadline, ?string $deadlineTime = null): ?DateTimeImmutable
{
    if ($deadline === null || $deadline === '') return null;
    $time = $deadlineTime !== null && $deadlineTime !== '' ? $deadlineTime : '23:59:59';
    if (preg_match('/^\d{2}:\d{2}$/D', $time)) $time .= ':00';
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time)) return null;
    $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $deadline . ' ' . $time, new DateTimeZone('Asia/Manila'));
    $errors = DateTimeImmutable::getLastErrors();
    return $due && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $due : null;
}

function app_task_is_overdue(?string $deadline, ?string $deadlineTime = null, ?DateTimeImmutable $now = null): bool
{
    $due = app_task_deadline_at($deadline, $deadlineTime);
    if ($due === null) return false;
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $due < $now;
}

function app_task_deadline_class(?string $deadline, ?string $deadlineTime, string $status, ?DateTimeImmutable $now = null): string
{
    if ($status === 'Completed') return '';
    $due = app_task_deadline_at($deadline, $deadlineTime);
    if ($due === null) return '';
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    if ($due < $now) return 'overdue';
    return $due <= $now->modify('+24 hours') ? 'due-soon' : '';
}

function app_task_deadline_display(?string $deadline, ?string $deadlineTime, string $dateFormat = 'M j, Y'): string
{
    if ($deadline === null || $deadline === '') return 'No deadline';
    $display = date($dateFormat, strtotime($deadline));
    if ($deadlineTime !== null && $deadlineTime !== '') {
        $display .= ' at ' . date('g:i A', strtotime($deadlineTime));
    }
    return $display;
}

function app_task_overdue_sql(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid task table alias.');
    }
    $prefix = $alias === '' ? '' : $alias . '.';
    return '(' . $prefix . 'deadline < CURDATE() OR (' . $prefix . 'deadline = CURDATE() AND ' . $prefix . 'deadline_time IS NOT NULL AND ' . $prefix . 'deadline_time < CURTIME()))';
}

function app_task_deadline_upcoming_sql(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid task table alias.');
    }
    $prefix = $alias === '' ? '' : $alias . '.';
    return '(' . $prefix . 'deadline > CURDATE() OR (' . $prefix . 'deadline = CURDATE() AND (' . $prefix . 'deadline_time IS NULL OR ' . $prefix . 'deadline_time >= CURTIME())))';
}
