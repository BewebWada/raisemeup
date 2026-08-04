<?php
class ImageProcessor
{
    // 受信した画像バイナリを長辺$maxEdge px以内にリサイズし、JPEGとしてbase64エンコードして返す。
    // 出力は常にJPEGに統一する(入力がPNG等でもClaude側のmedia_typeをimage/jpegに固定できるため)。
    // 不正/破損データの場合はnullを返す。ディスクへの書き込みは行わない。
    public static function resizeToJpegBase64(string $binary, int $maxEdge = 1092, int $quality = 80): ?string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }

        $longEdge = max($width, $height);
        if ($longEdge > $maxEdge) {
            $scale = $maxEdge / $longEdge;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        ob_start();
        $success = imagejpeg($source, null, $quality);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        if (!$success || $jpegData === false) {
            return null;
        }

        return base64_encode($jpegData);
    }
}
