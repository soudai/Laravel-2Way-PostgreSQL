<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Compiler\Limits;
use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Hft\TwoWaySql\Template\TemplateLoader;
use Illuminate\Console\Command;

abstract class TemplateCommand extends Command
{
    protected function inspect(TemplateRepository $repository, ?AstCache $cache = null): int
    {
        $failures = 0;
        foreach ($repository->ids() as $id) {
            try {
                $definition = $repository->get($id);
                $this->laravel->make(CodecRegistry::class)->validate($definition->parameters);
                $limits = $this->laravel->make(Limits::class);
                (new TemplateLoader($this->laravel->make(TemplateParser::class), $cache, $limits->hash()))->load($definition);
                $lexer = new PgLexer($limits->get('template_bytes'), $limits->get('tokens'), $limits->get('depth'));
                foreach ($lexer->tokenize($definition->source, $definition->path) as $token) {
                    if ($definition->grammar === 'canonical-v1' && $token->kind === TokenKind::Comment && preg_match('/\A\/\*\s*[A-Za-z_][A-Za-z0-9_.]*\s*\*\/\z/D', $token->rawText)) {
                        $this->warn($id.': legacy-like comment at '.$token->span->line.':'.$token->span->column.' (ordinary comment in canonical mode)');
                    }
                }
                $this->line($id.': OK');
            } catch (SqlException $e) {
                $failures++;
                $location = $e->span === null ? '' : ' '.$e->span->line.':'.$e->span->column;
                $this->error($id.': '.$e->errorCode.$location);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
