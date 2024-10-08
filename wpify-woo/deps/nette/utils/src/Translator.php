<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */
declare (strict_types=1);
namespace WpifyWooDeps\Nette\Localization;

/**
 * Translator adapter.
 */
interface Translator
{
    /**
     * Translates the given string.
     */
    function translate(string|\Stringable $message, mixed ...$parameters) : string|\Stringable;
}
\interface_exists(ITranslator::class);
