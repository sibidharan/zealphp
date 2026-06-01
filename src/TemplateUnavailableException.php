<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * Thrown when a requested template file does not exist.
 *
 * Extracted verbatim from src/App.php (Phase 0 structural relocation). FQCN
 * unchanged (`ZealPHP\TemplateUnavailableException`).
 */
class TemplateUnavailableException extends \Exception {

	/** @var string */
	protected $message = "The template you are trying to include does not seem to exist. Please check the file name.
	Invalid error message. ";
	/** @var int */
	protected $code = 1002;

	public function __construct(string $message) {
		$this->message = $message;
		parent::__construct($this->message, $this->code);
	}

	public function __toString() {
		return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
	}

}
