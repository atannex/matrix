<?php

namespace Atannex\Matrix\Tests\Unit;

use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Tests\TestCase;

class TokensTest extends TestCase
{
    public function test_tokens_do_not_reveal_the_model_or_its_key(): void
    {
        $article = $this->article();
        $token = $article->matrixToken();
        [$payload] = explode('.', $token);

        $decoded = (string) base64_decode(strtr($payload, '-_', '+/'));

        $this->assertStringNotContainsString('Article', $decoded);
        $this->assertStringNotContainsString('|'.$article->getKey().'|', $decoded);
        $this->assertTrue($article->is(Matrix::tokens()->resolve($token)));
    }

    public function test_tampered_tokens_are_refused(): void
    {
        $token = $this->article()->matrixToken();
        [$payload, $signature] = explode('.', $token);

        $this->assertNull(Matrix::tokens()->resolve(strrev($payload).'.'.$signature));
        $this->assertNull(Matrix::tokens()->resolve($payload.'.'.strrev($signature)));
    }
}
