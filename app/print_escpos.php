<?php
declare(strict_types=1);

/**
 * Minimal ESC/POS builder + TCP sender
 * - Works with many receipt printers in ESC/POS mode.
 * - For Star mC-Print2: printing Japanese may depend on codepage/settings.
 *   If Japanese gets garbled, we can switch to "raster image" printing later.
 */
final class EscposBuilder {
  private string $buf = '';

  public function raw(string $s): self { $this->buf .= $s; return $this; }

  public function init(): self { return $this->raw("\x1b\x40"); }            // ESC @
  public function resetStyle(): self { return $this->raw("\x1b\x21\x00"); } // ESC ! 0
  public function align(string $pos): self {
    $n = 0; if ($pos==='center') $n=1; elseif($pos==='right') $n=2;
    return $this->raw("\x1b\x61".chr($n)); // ESC a n
  }
  public function bold(bool $on): self { return $this->raw("\x1b\x45".chr($on?1:0)); } // ESC E
  public function underline(bool $on): self { return $this->raw("\x1b\x2d".chr($on?1:0)); } // ESC -
  public function size(int $wMul, int $hMul): self {
    $wMul = max(1, min(8, $wMul));
    $hMul = max(1, min(8, $hMul));
    $n = (($wMul - 1) << 4) | ($hMul - 1);
    return $this->raw("\x1d\x21".chr($n)); // GS ! n
  }
  public function text(string $s, bool $lf=true): self {
    // ESC/POS printers typically expect Shift_JIS / CP932 for Japanese.
    // Convert UTF-8 -> SJIS-win (CP932) before appending.
    $out = $s;
    if (function_exists('mb_convert_encoding')) {
      $out = mb_convert_encoding($s, 'SJIS-win', 'UTF-8');
    } elseif (function_exists('iconv')) {
      $conv = @iconv('UTF-8', 'CP932//TRANSLIT', $s);
      if ($conv !== false) $out = $conv;
    }

    $this->buf .= $out;
    if ($lf) $this->buf .= "\n";
    return $this;
  }
  public function feed(int $n=1): self { return $this->raw(str_repeat("\n", max(0,$n))); }
  public function hr(int $cols=48): self {
    return $this->text(str_repeat('-', max(10, $cols)));
  }
  public function cut(bool $full=true): self {
    // 代表的なカットコマンドを複数送って互換性を高める
    // 1) GS V m (一般的な方式)
    $this->raw("\x1d\x56".chr($full?0:1));
    // 2) ESC i / ESC m（プリンタによってはこちらを使うものがある）
    $this->raw($full ? "\x1b\x69" : "\x1b\x6d");
    return $this;
  }
  public function build(): string { return $this->buf; }
}

/**
 * Send raw bytes to printer over TCP.
 */
function escpos_tcp_send(string $ip, int $port, string $payload, int $timeoutSec=3): void {
  $errno = 0; $errstr = '';
  $fp = @fsockopen($ip, $port, $errno, $errstr, $timeoutSec);
  if (!$fp) {
    throw new RuntimeException("TCP接続失敗: {$ip}:{$port} ({$errno}) {$errstr}");
  }
  stream_set_timeout($fp, $timeoutSec);
  $len = strlen($payload);
  $written = 0;
  while ($written < $len) {
    $w = fwrite($fp, substr($payload, $written));
    if ($w === false) { fclose($fp); throw new RuntimeException("TCP送信失敗: fwrite error"); }
    $written += $w;
  }
  fflush($fp);
  fclose($fp);
}