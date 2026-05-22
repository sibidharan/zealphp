<?php
/**
 * Shared docs search box — included from the landing, guide, and api-
 * wrapped templates. Pure-htmx, no JS framework: input debounces,
 * htmx GETs /api/docs/search, swaps the HTML fragment into the
 * dropdown. ESC clears + blurs, ↓ jumps into results.
 *
 * Searches across BOTH the 16 markdown guides (their H1/H2/H3
 * headings) AND every phpDocumentor symbol — single index, one box.
 */
?>
<div class="api-search">
  <svg class="api-search-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
    <path d="M9 17A8 8 0 109 1a8 8 0 000 16zm5.5-2.5l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
  </svg>
  <input type="search"
         name="q"
         placeholder="Search docs — guides, classes, methods, properties…"
         autocomplete="off"
         spellcheck="false"
         class="api-search-input"
         hx-get="/api/docs/search"
         hx-trigger="input changed delay:180ms, search"
         hx-target="#api-search-results"
         hx-swap="innerHTML"
         hx-indicator=".api-search-spinner"
         onkeydown="if(event.key==='Escape'){this.value='';document.getElementById('api-search-results').replaceChildren();this.blur();}else if(event.key==='ArrowDown'){const f=document.querySelector('#api-search-results .api-search-item');if(f){event.preventDefault();f.focus();}}">
  <div class="api-search-spinner htmx-indicator" aria-hidden="true"></div>
  <kbd class="api-search-hint">Esc</kbd>
  <div id="api-search-results"
       class="api-search-results"
       tabindex="-1"
       onkeydown="if(event.key==='Escape'){const i=document.querySelector('.api-search-input');i.value='';this.replaceChildren();i.focus();}else if(event.key==='ArrowDown'||event.key==='ArrowUp'){const items=Array.from(this.querySelectorAll('.api-search-item'));const cur=items.indexOf(document.activeElement);if(cur===-1)return;event.preventDefault();const nxt=event.key==='ArrowDown'?items[cur+1]:items[cur-1];if(nxt)nxt.focus();else if(event.key==='ArrowUp')document.querySelector('.api-search-input').focus();}"></div>
</div>
