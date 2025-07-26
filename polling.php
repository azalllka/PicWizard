<?php
require_once __DIR__ . '/vendor/autoload.php';

use TelegramBot\Api\BotApi;
use Intervention\Image\ImageManager;

$BOT_TOKEN = '7892064244:AAEg5WiU2OoCpaP0vSUdpIHBQiF6e9tZEPg';
$MAX_ERRORS = 10;
$POLL_TIMEOUT = 30;
$SLEEP_INTERVAL = 1;

$telegram = new BotApi($BOT_TOKEN);
$imageManager = new ImageManager(['driver' => 'gd']);
$standardSizes = [
    '1:1' => [1, 1],
    '4:3' => [4, 3],
    '16:9' => [16, 9],
    '9:16' => [9, 16],
    'A4' => [2480, 3508]
];

function logMessage($message) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
}

if (!file_exists('temp')) {
    mkdir('temp', 0777, true);
}

function sendWelcomeMessage($telegram, $chatId) {
    $text = "*Бот для обработки изображений*\n\n";
    $text .= "Отправьте мне изображение, и я могу:\n";
    $text .= "1. Обрезать по стандартным размерам\n";
    $text .= "2. Преобразовать в ч/б\n";
    $text .= "3. Изменить формат файла (JPG/PNG/TIFF)\n\n";
    $text .= "Просто отправьте мне фото!";
    
    $telegram->sendMessage($chatId, $text, 'Markdown');
}

function sendProcessingMenu($telegram, $chatId) {
    $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup(
        [
            ['size_1:1', 'size_4:3', 'size_16:9', 'size_9:16', 'size_A4'],
            ['bw', 'format_jpg', 'format_png', 'format_tiff'],
            ['process']
        ],
        true,
        true
    );
    
    $telegram->sendMessage(
        $chatId,
        "Выберите действия с изображением:\n\n".
        "• Размер: size_1:1, size_4:3, size_16:9, size_9:16, size_A4\n".
        "• Формат: format_jpg, format_png, format_tiff\n".
        "• Ч/Б: bw\n".
        "• Обработать: process",
        null,
        false,
        null,
        $keyboard
    );
}

function getImageData($chatId) {
    $file = "temp/image_$chatId.json";
    return file_exists($file) ? json_decode(file_get_contents($file), true) : null;
}

function saveImageData($chatId, $data) {
    file_put_contents("temp/image_$chatId.json", json_encode($data));
}

function processSizeSelection($telegram, $chatId, $text) {
    $size = str_replace('size_', '', $text);
    $imageData = getImageData($chatId);
    
    if ($imageData) {
        $imageData['processing']['size'] = $size;
        saveImageData($chatId, $imageData);
        $telegram->sendMessage($chatId, "✅ Выбран размер: $size");
    }
}

function processFormatSelection($telegram, $chatId, $text) {
    $format = str_replace('format_', '', $text);
    $imageData = getImageData($chatId);
    
    if ($imageData) {
        $imageData['processing']['format'] = $format;
        saveImageData($chatId, $imageData);
        $telegram->sendMessage($chatId, "✅ Выбран формат: $format");
    }
}

function processBlackWhite($telegram, $chatId) {
    $imageData = getImageData($chatId);
    
    if ($imageData) {
        $imageData['processing']['bw'] = true;
        saveImageData($chatId, $imageData);
        $telegram->sendMessage($chatId, "✅ Изображение будет преобразовано в ч/б");
    }
}

function processImage($telegram, $chatId, $imageManager, $standardSizes) {
    $imageData = getImageData($chatId);
    
    if (!$imageData) {
        $telegram->sendMessage($chatId, "❌ Нет изображения для обработки");
        return;
    }
    
    try {
        $imageContents = file_get_contents($imageData['file_url']);
        if ($imageContents === false) {
            throw new Exception("Не удалось загрузить изображение");
        }
        
        $tempInput = "temp/input_$chatId." . pathinfo($imageData['file_url'], PATHINFO_EXTENSION) ?: 'jpg';
        file_put_contents($tempInput, $imageContents);
        
        $image = $imageManager->make($tempInput);
        $originalWidth = $image->width();
        $originalHeight = $image->height();
        
        // Обработка размера
        if (!empty($imageData['processing']['size'])) {
            $size = $imageData['processing']['size'];
            
            if (in_array($size, ['1:1', '4:3', '16:9', '9:16'])) {
                list($widthRatio, $heightRatio) = array_map('intval', explode(':', $size));
                
                $targetRatio = $widthRatio / $heightRatio;
                $currentRatio = $originalWidth / $originalHeight;
                
                if ($currentRatio > $targetRatio) {
                    $newWidth = $originalHeight * $targetRatio;
                    $x = ($originalWidth - $newWidth) / 2;
                    $image->crop((int)$newWidth, $originalHeight, (int)$x, 0);
                } else {
                    $newHeight = $originalWidth / $targetRatio;
                    $y = ($originalHeight - $newHeight) / 2;
                    $image->crop($originalWidth, (int)$newHeight, 0, (int)$y);
                }
            } elseif ($size === 'A4') {
                $image->resize(2480, 3508, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize(); 
                });
            }
        }
        
        // Ч/Б обработка
        if (!empty($imageData['processing']['bw'])) {
            $image->greyscale();
        }
        
        // Определение формата вывода
        $format = strtolower($imageData['processing']['format'] ?? 'jpg');
        $fileName = "output_$chatId." . $format;
        $tempOutput = "temp/$fileName";
        
        switch ($format) {
            case 'png':
                $image->encode('png', 9); 
                $mimeType = 'image/png';
                break;
            case 'tiff':
                if (!extension_loaded('imagick')) {
                    throw new Exception("Для работы с TIFF требуется Imagick. Установите:\n1. brew install imagemagick\n2. pecl install imagick");
                }
                
                try {
                    $tempJpg = "temp/temp_$chatId.jpg";
                    $image->save($tempJpg, 100);
                    
                    $imagick = new Imagick();
                    $imagick->readImage($tempJpg);
                    $imagick->setImageFormat('tiff');
                    $imagick->setImageCompression(Imagick::COMPRESSION_LZW);
                    $imagick->writeImage($tempOutput);
                    $imagick->clear();
                    
                    $mimeType = 'image/tiff';
                    unlink($tempJpg);
                } catch (Exception $e) {
                    if (file_exists($tempJpg)) unlink($tempJpg);
                    throw new Exception("Ошибка конвертации в TIFF: ".$e->getMessage());
                }
                break;
            case 'jpg':
            case 'jpeg':
            default:
                $image->encode('jpg', 100); 
                $mimeType = 'image/jpeg';
                break;
        }
        
        if ($format !== 'tiff') {
            $image->save($tempOutput);
        }
        
        $document = new \CURLFile($tempOutput, $mimeType, $fileName);
        $telegram->sendDocument(
            $chatId,
            $document,
            "✅ Ваше обработанное изображение"
        );

        @unlink($tempInput);
        @unlink($tempOutput);
        @unlink("temp/image_$chatId.json");
        
    } catch (Exception $e) {
        $telegram->sendMessage($chatId, "❌ Ошибка обработки: " . $e->getMessage());
        error_log($e->getMessage());
    }
}

try {
    $telegram->deleteWebhook();
    logMessage("Webhook удален, запускаем polling...");
} catch (Exception $e) {
    logMessage("Ошибка удаления вебхука: " . $e->getMessage());
}

$lastUpdateId = 0;
$errorCount = 0;

while (true) {
    try {
        $updates = $telegram->getUpdates($lastUpdateId + 1, 100, $POLL_TIMEOUT);
        
        if (empty($updates)) {
            sleep($SLEEP_INTERVAL);
            continue;
        }
        
        foreach ($updates as $update) {
            $lastUpdateId = $update->getUpdateId();
            $message = $update->getMessage();
            
            if (!$message) {
                continue;
            }
            
            $chatId = $message->getChat()->getId();
            
            if ($text = $message->getText()) {
                if ($text === '/start') {
                    sendWelcomeMessage($telegram, $chatId);
                } elseif (strpos($text, 'size_') === 0) {
                    processSizeSelection($telegram, $chatId, $text);
                } elseif (strpos($text, 'format_') === 0) {
                    processFormatSelection($telegram, $chatId, $text);
                } elseif ($text === 'bw') {
                    processBlackWhite($telegram, $chatId);
                } elseif ($text === 'process') {
                    processImage($telegram, $chatId, $imageManager, $standardSizes);
                }
            }
            
            if ($photos = $message->getPhoto()) {
                $photo = end($photos);
                try {
                    $file = $telegram->getFile(['file_id' => $photo->getFileId()]);
                    $fileUrl = "https://api.telegram.org/file/bot{$BOT_TOKEN}/" . $file->getFilePath();
                    
                    $imageData = [
                        'file_url' => $fileUrl,
                        'chat_id' => $chatId,
                        'processing' => [
                            'size' => null,
                            'format' => 'jpg',
                            'bw' => false
                        ]
                    ];
                    
                    saveImageData($chatId, $imageData);
                    sendProcessingMenu($telegram, $chatId);
                } catch (Exception $e) {
                    logMessage("Ошибка обработки фото: " . $e->getMessage());
                    $telegram->sendMessage($chatId, "❌ Ошибка при загрузке изображения");
                }
            }
        }
        
        $errorCount = 0;
        
    } catch (Exception $e) {
        $errorCount++;
        logMessage("Ошибка (#{$errorCount}): " . $e->getMessage());
        
        if ($errorCount >= $MAX_ERRORS) {
            logMessage("Достигнут лимит ошибок ({$MAX_ERRORS}), завершение работы");
            exit(1);
        }
        
        sleep(min(30, $errorCount * 5));
    }
}