<?php
declare(strict_types=1);

function page_header(string $title = 'FontSeller'): void
{
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - FontSeller</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 500: '#22c55e', 600: '#16a34a', 700: '#15803d' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col">
<header class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
        <a href="<?= e(url('/')) ?>" class="flex items-center gap-2">
            <span class="text-2xl font-bold text-brand-600">FontSeller</span>
            <span class="text-sm text-gray-500 hidden sm:inline">ชุดฟอนต์ทั้งหมด</span>
        </a>
        <span class="text-sm font-semibold text-brand-700 bg-brand-50 px-3 py-1 rounded-full">100 บาท</span>
    </div>
</header>
<main class="flex-1">
    <?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="bg-white border-t border-gray-200 mt-8">
    <div class="max-w-7xl mx-auto px-4 py-6 text-center text-sm text-gray-500">
        &copy; <?= date('Y') ?> FontSeller. ฟอนต์ทั้งหมดมีสิทธิ์การเผยแพร่ตามที่เจ้าของลิขสิทธิ์อนุญาต
    </div>
</footer>
</body>
</html>
    <?php
}
