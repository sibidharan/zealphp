<?php use ZealPHP\App; $active = $active ?? 'learn/react-vs-php'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 5,
      'title'    => 'React vs PHP',
      'subtitle' => 'Why most web apps don\'t need a JavaScript framework — and what to use instead.',
      'prev'     => ['slug' => 'learn/components', 'title' => 'Layouts & Components'],
      'next'     => ['slug' => 'learn/htmx', 'title' => 'Forms & htmx'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What React actually solves &mdash; and what it doesn\'t',
      'The hidden cost of a JavaScript-first architecture',
      'Why server-rendered HTML + htmx covers 95% of web apps',
      'When you SHOULD reach for React (and when you shouldn\'t)',
    ]]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'ZealPHP is frontend-agnostic',
      'body'    => '<p>ZealPHP is a <strong>PHP runtime</strong>, not a religion. It works with React, Vue, Svelte, or plain HTML. It can serve a JSON API for a React SPA, stream HTML for htmx, or run an unmodified WordPress. This lesson isn\'t "React bad" &mdash; it\'s "know when you need it and when you don\'t."</p>',
    ]); ?>

    <h2>The question nobody asks</h2>
    <p>
      When a team starts a new web project in 2026, the default is React. Not because they evaluated
      alternatives. Not because the project needs client-side state management. Just because "that's
      what you use." Nobody asks: <em>does this project actually need a JavaScript framework?</em>
    </p>
    <p>
      This lesson asks that question. The answer, for most projects, is no.
    </p>

    <h2>What React actually solves</h2>
    <p>
      React was built at Facebook to solve a specific problem: <strong>complex, interactive UIs with
      lots of shared client-side state</strong>. The news feed, where a like on one post updates a
      counter in the header, a notification badge, and a sidebar &mdash; all at once, without a page
      reload. That's genuinely hard without a framework.
    </p>
    <p>React is brilliant for:</p>
    <ul>
      <li><strong>Spreadsheet-like interfaces</strong> &mdash; cells that depend on other cells</li>
      <li><strong>Design tools</strong> &mdash; Figma, Canva, drag-and-drop builders</li>
      <li><strong>Collaborative editors</strong> &mdash; Google Docs, real-time cursors</li>
      <li><strong>Complex dashboards</strong> &mdash; 50+ interactive widgets, filters that affect each other</li>
    </ul>
    <p>
      These are <strong>applications that live in the browser</strong>. The server is a data API. The
      client IS the application.
    </p>

    <h2>What most teams actually build</h2>
    <p>
      But most web projects aren't Figma. They're:
    </p>
    <ul>
      <li>A SaaS dashboard with forms, tables, and charts</li>
      <li>An admin panel for managing content</li>
      <li>A landing page with a contact form</li>
      <li>An e-commerce site with product listings and checkout</li>
      <li>An internal tool for querying databases</li>
      <li>A blog, a docs site, a learning platform (like this one)</li>
    </ul>
    <p>
      These are <strong>document-centric applications</strong>. The server has the data. The client
      displays it. Interactions are form submissions, list filters, and page navigation. React
      solves a problem they don't have.
    </p>

    <h2>The hidden cost</h2>
    <p>
      Choosing React for a document-centric app doesn't just add complexity. It <em>multiplies</em> it:
    </p>

    <pre class="mermaid">graph LR
    subgraph "React + Node stack"
      R[React App] --> B[Bundler<br/>Webpack/Vite]
      B --> H[Hydration]
      R --> S[State Mgmt<br/>Redux/Zustand]
      R --> API[REST/GraphQL API]
      API --> N[Node.js Server]
      N --> DB[(Database)]
      N --> RD[(Redis)]
      N --> Q[Queue Worker]
    end
    style R fill:#fef2f2,stroke:#f87171
    style B fill:#fef2f2,stroke:#f87171
    style H fill:#fef2f2,stroke:#f87171
    style S fill:#fef2f2,stroke:#f87171
    style N fill:#fef2f2,stroke:#f87171
    style RD fill:#fef2f2,stroke:#f87171
    style Q fill:#fef2f2,stroke:#f87171</pre>

    <pre class="mermaid">graph LR
    subgraph "ZealPHP + htmx"
      P["php app.php"] --> T[Templates<br/>PHP + HTML]
      T --> HX[htmx attrs]
      P --> DB2[(SQLite/MySQL)]
      P --> WS2[WebSocket]
      P --> SSE[SSE Streaming]
    end
    style P fill:#ecfdf5,stroke:#059669,stroke-width:2px
    style T fill:#ecfdf5,stroke:#059669
    style HX fill:#ecfdf5,stroke:#059669
    style WS2 fill:#ecfdf5,stroke:#059669
    style SSE fill:#ecfdf5,stroke:#059669</pre>

    <table style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:.88rem">
      <thead><tr style="border-bottom:2px solid #e7e5e4;text-align:left">
        <th style="padding:.55rem">Concern</th>
        <th style="padding:.55rem">React + Node</th>
        <th style="padding:.55rem">ZealPHP + htmx</th>
      </tr></thead>
      <tbody>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>Languages</strong></td><td style="padding:.55rem">JavaScript + TypeScript + JSX + CSS-in-JS</td><td style="padding:.55rem">PHP + HTML + CSS</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>Build step</strong></td><td style="padding:.55rem">Webpack/Vite, 30s&ndash;2min</td><td style="padding:.55rem">None. Save and refresh.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>Client JS</strong></td><td style="padding:.55rem">200&ndash;500 KB (min+gzip)</td><td style="padding:.55rem">14 KB (htmx) + 0 custom</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>Hydration</strong></td><td style="padding:.55rem">Server renders HTML, client re-renders it in JS</td><td style="padding:.55rem">Server renders HTML. Done.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>State management</strong></td><td style="padding:.55rem">Redux / Zustand / Context + hooks</td><td style="padding:.55rem">Server is the state. Session + DB.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>API layer</strong></td><td style="padding:.55rem">REST or GraphQL + client fetching + loading states</td><td style="padding:.55rem">htmx posts, server returns HTML fragment</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>Routing</strong></td><td style="padding:.55rem">Client-side router + server routes + code splitting</td><td style="padding:.55rem">File = URL. One router.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>SEO</strong></td><td style="padding:.55rem">SSR/SSG required (Next.js, Remix)</td><td style="padding:.55rem">Server-rendered by default</td></tr>
        <tr><td style="padding:.55rem"><strong>Processes</strong></td><td style="padding:.55rem">Node + Redis + queue + maybe Nginx</td><td style="padding:.55rem"><code>php app.php</code></td></tr>
      </tbody>
    </table>

    <h2>The htmx insight</h2>
    <p>
      React replaces the browser's rendering model. You write JSX, React builds a virtual DOM,
      diffs it, and patches the real DOM. This makes sense when the client IS the application.
    </p>
    <p>
      But for document-centric apps, the browser's rendering model is fine. You just need to
      <strong>swap parts of the page without a full reload</strong>. That's exactly what htmx does:
    </p>
    <pre><code class="language-html">&lt;!-- React: 47 lines --&gt;
const [items, setItems] = useState([]);
const [loading, setLoading] = useState(false);
const handleSubmit = async (e) =&gt; {
  e.preventDefault();
  setLoading(true);
  const res = await fetch('/api/items', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({title: input})
  });
  const newItem = await res.json();
  setItems([newItem, ...items]);
  setLoading(false);
  setInput('');
};
// ... plus JSX template, useEffect for initial load,
// error handling, TypeScript types, etc.

&lt;!-- htmx: 4 attributes --&gt;
&lt;form hx-post="/api/items"
      hx-target="#list"
      hx-swap="afterbegin"
      hx-on::after-request="this.reset()"&gt;</code></pre>
    <p>
      Same result. The server renders the HTML fragment. htmx swaps it in. No state management,
      no loading spinners, no JSON parsing, no TypeScript types for the response shape.
    </p>

    <h2>But what about...</h2>

    <?php App::render('/components/_deepdive', [
      'title' => '"Interactivity! You can\'t do interactive UIs without React"',
      'body'  => '<p>This tutorial app has: a counter that persists across sessions, a CRUD notes app with inline create/delete, an AI chat with streaming tokens and tool call cards, cross-tab WebSocket sync with green highlight animations, and an event log terminal. Total custom JavaScript: <strong>~100 lines</strong>. Zero React.</p><p>htmx handles form submissions, list updates, and navigation. The ~100 lines of vanilla JS handle SSE streaming (for the AI chat) and WebSocket (for cross-tab sync) &mdash; things that are genuinely beyond request/response.</p>',
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => '"Performance! React is faster because virtual DOM"',
      'body'  => '<p>The virtual DOM exists to make <em>React</em> fast, not to make your app fast. It\'s overhead that wouldn\'t exist if you weren\'t using React in the first place. Server-rendered HTML arrives ready to display &mdash; no JavaScript parse, no hydration, no re-render.</p><p>ZealPHP on 4 workers: <strong>117,000 req/s</strong>, 3ms p90 latency. The browser gets HTML it can render instantly. There\'s nothing faster than "the server already did the work."</p>',
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => '"Ecosystem! npm has a package for everything"',
      'body'  => '<p>And PHP has 25 years of battle-tested libraries on Packagist. PDO for databases, password_hash for security, OpenSwoole for async. The ecosystem argument is a wash &mdash; both are massive. The difference is that PHP\'s ecosystem doesn\'t require a bundler to use.</p>',
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => '"Developer experience! Hot reload, TypeScript, DevTools"',
      'body'  => '<p>ZealPHP: save the file, refresh the browser. No compile step, no waiting for webpack, no HMR socket disconnections. PHP errors show on the page with file + line number. Chrome DevTools shows the HTML the server sent &mdash; what you see is what you debug.</p><p>TypeScript catches bugs at compile time. PHP 8.3\'s type system catches them at runtime, and PHPStan catches them statically. Different tradeoff, same goal.</p>',
    ]); ?>

    <h2>When to reach for React</h2>
    <p>Use React (or Vue, Svelte, etc.) when:</p>
    <ul>
      <li>The UI has <strong>complex shared state</strong> &mdash; changing one thing updates many parts</li>
      <li>The app is <strong>offline-first</strong> &mdash; the client needs to work without the server</li>
      <li>You're building a <strong>design tool, game, or editor</strong> &mdash; the browser is the runtime</li>
      <li>The team <strong>already has React expertise</strong> and the project benefits from it</li>
    </ul>
    <p>Use server-rendered PHP + htmx when:</p>
    <ul>
      <li>The UI is <strong>forms, tables, lists, and pages</strong></li>
      <li>The server has the data and the client displays it</li>
      <li>You want <strong>zero build step</strong> and instant deploys</li>
      <li>You need <strong>SEO</strong> without SSR/SSG complexity</li>
      <li>You want <strong>one process</strong> instead of six</li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'react1',
      'question' => 'You\'re building an internal admin panel with user management, a settings page, and a dashboard with charts. Which approach fits best?',
      'correct'  => 'b',
      'explain'  => 'An admin panel is a document-centric app: forms, tables, navigation. Server-rendered HTML with htmx for interactivity covers it with far less complexity than React.',
      'options'  => [
        'a' => 'React + Next.js + Redux + REST API',
        'b' => 'Server-rendered PHP + htmx',
        'c' => 'Static HTML with jQuery',
      ],
    ]); ?>

    <h2>What this tutorial proves</h2>
    <p>
      You're reading a tutorial that is also a working app. It has auth, a database, CRUD, AI streaming,
      WebSocket sync, interactive quizzes, zoomable diagrams, and an event log. The entire client-side
      JavaScript is <strong>~100 lines</strong>. No React. No bundler. No node_modules.
    </p>
    <p>
      The next lesson shows you htmx &mdash; the 14 KB library that makes this possible.
    </p>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'React solves client-side state management &mdash; most web apps don\'t have that problem',
      'Server-rendered HTML + htmx replaces React for forms, tables, lists, and pages',
      'The hidden cost of React: bundler, hydration, state management, API layer, client-side routing',
      'ZealPHP + htmx: one process, zero build step, 14 KB of JS, server is the single source of truth',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/components">&larr; Layouts &amp; Components</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/htmx"
         hx-get="/api/learn/page?slug=learn/htmx" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/htmx">Forms &amp; htmx &rarr;</a>
    </div>
  </article>
</div>
