<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Parameter\Range\DateMultirange;
use Hft\TwoWaySql\Parameter\Range\TstzMultirange;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;

/** Call once from a trusted service provider; SQL never selects arbitrary converter classes. */
final class PostgresTypes
{
    public static function register(CodecRegistry $registry, int $maxBytes = 8388608, int $maxItems = 10000): void
    {
        foreach (['int64' => ['int', 'int8[]'], 'text' => ['string', 'text[]'], 'uuid' => ['string', 'uuid[]'], 'bool' => ['bool', 'bool[]'], 'decimal' => ['string', 'numeric[]']] as $element => [$php, $postgres]) {
            foreach ([false, true] as $nullable) {
                $id = 'pg.array.'.$element.($nullable ? '.nullable_elements' : '');
                $doc = 'list<'.$php.($nullable ? '|null' : '').'>';
                $codec = new PgArrayCodec($element, $nullable, $maxBytes, $maxItems);
                $registry->registerType(new TypeDescriptor($id, 'array', 'array', $postgres, true, $doc, $doc), $codec, $codec);
            }
        }
        $json = new JsonDocumentCodec($maxBytes);
        $registry->registerType(new TypeDescriptor('pg.jsonb.document', JsonDocument::class, JsonDocument::class, 'jsonb', true), $json, $json);
        foreach (['date' => DateMultirange::class, 'tstz' => TstzMultirange::class] as $base => $class) {
            $codec = new MultirangeCodec($base, $maxBytes, $maxItems);
            $registry->registerType(new TypeDescriptor('pg.'.$base.'multirange', $class, $class, $base.'multirange', true), $codec, $codec);
        }
    }
}
