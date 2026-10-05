<?php

namespace Tests\Unit;

use App\Support\Gtin;
use PHPUnit\Framework\TestCase;

class GtinTest extends TestCase
{
    public function test_upc_a_and_ean_13_normalise_to_the_same_gtin_13(): void
    {
        $this->assertSame('0036000291452', Gtin::normalize('036000291452'));   // UPC-A
        $this->assertSame('0036000291452', Gtin::normalize('0036000291452'));  // same code as EAN-13
        $this->assertSame('0036000291452', Gtin::normalize('00036000291452')); // GTIN-14 with leading zero
    }

    public function test_ean_13_without_leading_zero_is_kept(): void
    {
        $this->assertSame('4006381333931', Gtin::normalize('4006381333931'));
    }

    public function test_ean_8_is_zero_padded(): void
    {
        $this->assertSame('0000096385074', Gtin::normalize('96385074'));
    }

    public function test_spaces_and_dashes_are_ignored(): void
    {
        $this->assertSame('0036000291452', Gtin::normalize(' 0 36000-29145 2 '));
    }

    public function test_bad_check_digit_is_rejected(): void
    {
        $this->assertNull(Gtin::normalize('036000291453'));
        $this->assertFalse(Gtin::isValid('4006381333932'));
    }

    public function test_wrong_length_or_non_digits_are_rejected(): void
    {
        $this->assertNull(Gtin::normalize('12345'));
        $this->assertNull(Gtin::normalize('03600029145A'));
        $this->assertNull(Gtin::normalize(''));
        $this->assertNull(Gtin::normalize(null));
    }

    public function test_gtin_14_without_leading_zero_cannot_be_a_gtin_13(): void
    {
        // valid GTIN-14 (indicator digit 1), but not representable as GTIN-13
        $body = '1003600029145';
        $code = $body.Gtin::checkDigitFor($body);
        $this->assertSame(14, strlen($code));
        $this->assertNull(Gtin::normalize($code));
    }

    public function test_upc_a_form_is_available_only_with_a_leading_zero(): void
    {
        $this->assertSame('036000291452', Gtin::toUpcA('0036000291452'));
        $this->assertNull(Gtin::toUpcA('4006381333931'));
    }
}
