<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * Product photos. Uploads must be real JPEG/PNG/WebP images (checked with getimagesize, max 3 MB) and are stored
 * under public/uploads/products with a random name. SVG uploads are not accepted (they can carry scripts);
 * SVG is used only for the app's own generated illustrations.
 */
final class ProductImageService
{
    public const DIR = '/public/uploads/products';

    public function __construct(private Database $db)
    {
    }

    public function storeUpload(string $productId, array $file): string
    {
        if (($file['size'] ?? 0) > 3_000_000) {
            throw new ValidationException('Image must be smaller than 3 MB');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if (!$ext) {
            throw new ValidationException('Upload a JPG, PNG or WebP photo');
        }
        if ($info[0] < 80 || $info[1] < 80 || $info[0] > 6000 || $info[1] > 6000) {
            throw new ValidationException('Image size must be between 80 and 6000 pixels');
        }
        $dir = APP_ROOT . self::DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = 'p_' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name") && !rename($file['tmp_name'], "$dir/$name")) {
            throw new ValidationException('Could not save the image');
        }
        $this->setProductImage($productId, 'uploads/products/' . $name);
        return 'uploads/products/' . $name;
    }

    public function setProductImage(string $productId, ?string $path): void
    {
        $old = $this->db->value('SELECT image_path FROM products WHERE id = ?', [$productId]);
        $this->db->update('products', ['image_path' => $path, 'updated_at' => now()], 'id = :id', ['id' => $productId]);
        // delete the previous uploaded photo (generated illustrations are kept; they may be shared by variants)
        if ($old && $old !== $path && str_starts_with((string)$old, 'uploads/products/p_')) {
            @unlink(APP_ROOT . '/public/' . $old);
        }
        Audit::log('product.image_changed', 'product', $productId, ['image' => $path]);
    }

    /** Write a generated illustration and return its relative path. */
    public static function writeArt(string $slug, string $svg): string
    {
        $dir = APP_ROOT . self::DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug));
        file_put_contents("$dir/$slug.svg", $svg);
        return "uploads/products/$slug.svg";
    }
}
