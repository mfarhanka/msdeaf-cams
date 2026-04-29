<?php

function getDelegateMenuItems(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'bi-speedometer2',
            'href' => 'dashboard.php',
            'setting_key' => 'delegate_menu_dashboard_visible',
        ],
        'athletes' => [
            'label' => 'Athletes & Officials',
            'icon' => 'bi-people',
            'href' => 'athletes.php',
            'setting_key' => 'delegate_menu_athletes_visible',
        ],
        'passport' => [
            'label' => 'Passport Details',
            'icon' => 'bi-passport',
            'href' => 'passport.php',
            'setting_key' => 'delegate_menu_passport_visible',
        ],
        'tshirt' => [
            'label' => 'T-Shirt Sizes',
            'icon' => 'bi-tag',
            'href' => 'tshirt.php',
            'setting_key' => 'delegate_menu_tshirt_visible',
        ],
        'book' => [
            'label' => 'Book Accommodation',
            'icon' => 'bi-calendar-check',
            'href' => 'book.php',
            'setting_key' => 'delegate_menu_book_visible',
        ],
        'rooming' => [
            'label' => 'Room Grouping',
            'icon' => 'bi-door-open',
            'href' => 'rooming.php',
            'setting_key' => 'delegate_menu_rooming_visible',
        ],
        'finance' => [
            'label' => 'Financial Summary',
            'icon' => 'bi-receipt',
            'href' => 'finance.php',
            'setting_key' => 'delegate_menu_finance_visible',
        ],
    ];
}

function getVisibleDelegateMenuItems(PDO $pdo): array
{
    $visibleMenuItems = [];

    foreach (getDelegateMenuItems() as $menuItemKey => $menuItem) {
        if (isAppSettingEnabled($pdo, $menuItem['setting_key'], true)) {
            $visibleMenuItems[$menuItemKey] = $menuItem;
        }
    }

    return $visibleMenuItems;
}

function findDelegateMenuItemByHref(string $href): ?array
{
    foreach (getDelegateMenuItems() as $menuItemKey => $menuItem) {
        if (($menuItem['href'] ?? '') === $href) {
            $menuItem['menu_key'] = $menuItemKey;

            return $menuItem;
        }
    }

    return null;
}