<?php

declare(strict_types=1);

namespace App\PhotoBuilder\TestHarness;

use App\PhotoBuilder\Infrastructure\Adapter\ImageGeneratorInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fake image generator that returns a placeholder PNG instantly.
 *
 * Generates a 512x512 light-grey image with "PLACEHOLDER" text,
 * avoiding any network calls to OpenAI.
 *
 * Enable via env var PHOTO_BUILDER_SIMULATE_IMAGE_GENERATION=1.
 */
final readonly class FakeImageGenerator implements ImageGeneratorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws Exception
     */
    public function generateImage(string $prompt, string $apiKey, ?string $imageSize = null): string
    {
        $this->logger->info('PhotoBuilder TestHarness: Generating fake placeholder image (skipping OpenAI call)');

        $width  = 512;
        $height = 512;

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('Failed to create placeholder image via GD.');
        }

        $bgColor   = imagecolorallocate($image, 230, 230, 230);
        $textColor = imagecolorallocate($image, 120, 120, 120);

        if ($bgColor === false || $textColor === false) {
            imagedestroy($image);

            throw new RuntimeException('Failed to allocate colors for placeholder image.');
        }

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $bgColor);

        $text       = 'PLACEHOLDER ' . DateAndTimeService::getDateTimeImmutable()->format('H:i:s');
        $fontSize   = 5;
        $textWidth  = imagefontwidth($fontSize) * mb_strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x          = (int) (($width - $textWidth) / 2);
        $y          = (int) (($height - $textHeight) / 2);

        imagestring($image, $fontSize, $x, $y, $text, $textColor);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        imagedestroy($image);

        if ($pngData === false || $pngData === '') {
            throw new RuntimeException('Failed to render placeholder PNG.');
        }

        sleep(1); // for realism

        return $pngData;
    }
}
