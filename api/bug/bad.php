<?php
/**
 * Live demo of ZealAPI's undefined-method behaviour.
 *
 * The handler intentionally typos $this->paramExist (singular) where it
 * meant $this->paramsExists (plural). The framework's __call now catches
 * this — pre-fix it recursed forever. Visit /api/bug/bad to see the
 * structured 404 response with did_you_mean suggestion.
 */
$bad = function() {
    if ($this->paramExist(['id'])) {   // typo: real method is paramsExists
        return ['id' => $_GET['id'] ?? 'n/a'];
    }
    return ['note' => 'unreachable — the typo short-circuits before this'];
};
