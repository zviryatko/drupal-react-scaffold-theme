# AI assistant

The module **React Scaffold Builder AI** (`react_scaffold_builder_ai`, shipped inside the builder module) adds an
assistant to the editor: describe a page or a change in a chat, and it composes the layout from the components of your
theme. It is built on the [AI module](https://www.drupal.org/project/ai) of Drupal and uses whichever provider and
model the site has configured there.

<Video src="/media/page-builder-ai.mp4" poster="/media/screenshots/page-builder-ai.png" />

*A session in the editor: the assistant builds a landing page (it searches the media library, checks what its layout
renders to and looks at a screenshot of the result), then adds an FAQ on request. After that the page is changed by
hand: texts, heights, colours, spacing and alignment in the right column, a paragraph dragged in and filled in its own
form, the history, Publish, and the published page. The model in this recording is a scripted stand-in.*

The assistant **only chooses and fills in components that exist**. It writes no code, no CSS and no new components.

## Set up

1. Install the AI module and a provider for it (OpenAI, Anthropic, Ollama, …), and add the provider's key
   (*Configuration → AI → Provider settings*).
2. Choose the default provider and model for **Chat** (*Configuration → AI → AI settings*).
3. Enable the assistant and give the permission:

```bash
composer require drupal/ai drupal/ai_provider_anthropic   # or another provider
drush en react_scaffold_builder_ai
```

| Permission | |
|---|---|
| *Use the AI assistant of the page builder* | Shows the **AI** panel. Needed in addition to the builder permission. Every request is a paid call to the provider: give it deliberately |

The [recipe](/builder/recipes) `react_scaffold_builder_ai` does step 3 and gives the permission to the Content editor
role; the provider and its key stay yours to set.

Without a default chat model the panel is there, with a note on what to set up. The builder itself does not depend on
the AI module.

Use a capable model. The assistant returns a whole page as JSON; small models lose the structure on larger pages.

## In the editor

The **AI** panel is in the left rail. Type what you want:

- "Make a landing page for a cooking course: a hero, three benefits, a quote and a sign-up call."
- "Put the three cards in columns and add a short intro above them."
- "Rewrite the headings shorter."

The answer appears in the chat, and when the assistant changed the page, the new layout is on the canvas:

- It is an **unsaved change**, one step in the history. Look at it, change it by hand, ask for another change, or undo
  it. Nothing is saved until you Publish or save a Draft.
- The conversation continues: the assistant sees the page as it is now with every request, including what you changed
  by hand in between.
- **Images come from the media library.** The assistant searches it by name and uses what it finds; it cannot upload
  or generate images, and never uses an image URL of its own. Items it keeps (it keeps their ids) keep their images,
  formatter settings and paragraphs.
- **It can check its work.** Before answering it may look at the HTML its layout renders to. With *Let the assistant
  look at the result* ticked, it may also ask for a picture of the canvas after its change, look at it and correct the
  page. The chat shows what it did: "Looked for images: …", "Checked what the page renders to", "A screenshot of the
  result was sent for a check".
- **Translations.** On a translation the assistant writes in the language of the page. On one that shares the
  structure of the original (the *synced* mode) it can change texts and links only, which makes it a translator:
  "Translate the texts of this page to Spanish". Whatever else it returns (other components, other options) is
  ignored. The content of Paragraph blocks is translated in their own form, not by the assistant.
- **Meta tags.** When the content type has a field of the [Metatag](https://www.drupal.org/project/metatag) module,
  the assistant sees the page's meta tags and can propose a title, description, abstract, keywords and the Open Graph
  and Twitter card titles and descriptions (those the site has). They appear, unsaved, in the *Meta tags* section of
  the page settings on the right, where you can change them; they are saved with the page. Ask for them ("Write meta
  tags for this page"); it also proposes them when it builds a page from nothing.
- **Tokens.** The assistant can look up which tokens exist before it uses one: `[node:title]`, `[site:name]`,
  `[node:author:display-name]`…

## How it works

```
editor ── POST /react-scaffold-builder/node/N/ai ─► PageAssistant
  {messages, data, image?}                            1. instructions (system prompt)
                                                      2. chat() of the default provider of the AI module
                                                      3. the answer is a tool request? run it, add the result to
                                                         the conversation, back to 2 (at most six times)
                                                      4. parse the final answer: {"message", "layout", "check"}
       ◄── {message, data, steps, dropped, check} ─   5. LayoutSanitizer → a layout the builder accepts
  check? wait for the canvas, take a picture,
  send one more turn with it
```

### What the model is told

`PageAssistant::instructions()` builds the system prompt for every request. It contains:

| Part | From |
|---|---|
| The rules: only listed components, props and options; no code | fixed text |
| The layout format: items, props, slots, ids, the common props `_spacing`, `_align`, `_background`, `_position`; how to lay out with the Layout components | fixed text |
| **Design tokens** (the UI kit): the spacing scale with its lengths, the palette with its colours, the image styles, the features every item has | the theme's `.info.yml` ([Tokens](/builder/styling#tokens)) |
| **The look of the frontend theme**: page background, text and link colours, fonts, the width of the layout area, the widths of the theme's breakpoints, the CSS custom properties on the root element | measured by the editor in the browser, in the page of the theme, and sent with every request |
| **Components**: for each its type, name, group and **description**; for each prop its type, label, **description**, options and an example value; its slots; and its **example**, a typical use as a layout item. Fields of the page and Drupal's blocks are listed by name only | the `*.component.yml` files, plus fields of the page, views, Drupal blocks, paragraph types and saved blocks |
| **The page**: title, content type, language | the node |
| **The current layout** | what is in the editor, unsaved changes included |
| The tools and how to ask for one | fixed text |
| **Translation**, on a translation: the two languages and what may be changed | the node |
| **Meta tags**, when the page has a Metatag field: the tags that may be set, with what each is for, and their values now | the Metatag field, with unsaved values |
| The answer format: one JSON object, `{"message": "...", "layout": [...] or null, "check": true or false, "metatags": {...}}` | fixed text |

So the quality of the assistant's choices comes from your components' metadata. **Write descriptions**: of the
component (what it is for, when to use it) and of every prop whose label does not say it all. That is the same text
editors see, and it is all the model knows about your design system.

Layout options are part of this: the props of Columns, Flex and Stack (columns, direction, gap, alignment, wrap) with
their choices, and spacing, alignment, background and position of every item. Saved blocks of the
[library](/builder/library) are listed as components of the group *Library*, and the model is told to prefer one that
fits. Props the model cannot fill sensibly are not offered to it: formatter settings, and the content of a paragraph (which
it can only copy from an existing one).

### The tools

The model works in steps. Instead of its final answer it may answer with a request for a tool; the result is added to
the conversation and it is asked again, at most six times per request.

| Tool | The model sends | It gets |
|---|---|---|
| `search_media` | words, or nothing for the newest | up to twelve images of the media library the user may see, matched on name, alternative text or file name: id, name, alternative text, file name, width and height |
| `search_paragraphs` | words, optionally a paragraph type | up to ten existing paragraphs the user may see, matched on their text fields: id, type, text. A Paragraph block can start as a **copy** of one (`"paragraph": {"copy": <id>}`): its content becomes the unsaved content of the new block; the paragraph that was copied is not shared or changed |
| `search_content` | words in a title | up to ten pages to link to: title, type and the link value |
| `list_tokens` | optionally a token type (`node`, `site`, `user`…) | up to eighty tokens with their name and description; without a type those of the page, the site and the date. A token that leads to more tokens says of which type |
| `page_shell` | — | the page as the frontend theme renders it around the layout (header, navigation, title, sidebars, footer), as short HTML without the administrative parts, so the model knows where the layout sits and what the theme already prints |
| `preview` | a layout | the HTML that layout renders to on this site (without scripts, styles and data attributes, at most 12,000 characters), how many of its items were left out as unknown, or the error if rendering failed |
| a screenshot (`"check": true` in the final answer) | — | a JPEG of the canvas as the editor shows it after the change, with one more turn to correct the page |

The tools are plain JSON messages, not the function calling of a provider, so they work with every chat model. The
screenshot needs a model that takes images, and is taken in the browser: it is exactly what the editor shows, with the
theme's CSS, up to 3000 pixels high. It happens at most once per request, and only when the box in the panel is ticked.

Each step is a request to the provider: a request that searches, previews and checks costs four calls instead of one.

The messages of the conversation (the last twelve) are sent as they are. The call is tagged `react_scaffold_builder`,
which shows in the AI module's logging.

To add guidance of your site (tone of voice, preferred components, what every page needs), alter the instructions:

```php
function my_module_react_scaffold_builder_ai_instructions_alter(string &$instructions, \Drupal\node\NodeInterface $node) {
  $instructions .= "\n\n# House style\nWrite headings in sentence case. Start a landing page with a Section.";
}
```

### What is done with the answer

The answer is text from a model, so none of it is trusted. `LayoutSanitizer` keeps only what an editor could have made
by hand:

- items of component types that exist; others are dropped, and the chat says how many;
- images only as media items that exist, are images and that the user may see; an image URL from the model is never
  used. A chosen image gets an image style the theme offers (a responsive one first), not the original file;
- for every prop a value of the kind its field takes: text as text, a choice only if it is one of the options, a
  length only from the scale or a valid CSS length, a colour only from the palette;
- props a component does not have are dropped;
- rich text is put in a text format the user may use, and is filtered by that format when the page is rendered, like
  text typed by the user;
- ids are made unique; an item that keeps the id and type of an item of the page keeps the values the model cannot see;
- on a translation that shares its structure, only the text and link props of the existing items are taken;
- meta tags: only the tags listed above, as plain text of a limited length. Other tags (robots, refresh, canonical…)
  cannot be set by the model.

The result goes through the same save as a hand-made layout when the user publishes, with the same checks.

The AI module's own safeguards apply as configured on the site, among them its filter for links to domains that are
not allowed: external URLs in an answer are removed unless the site allows the domain.

## Trying it without a provider

The request and the answer are plain text, so the assistant can be exercised by hand: a provider plugin that writes
each request to a file and waits for an answer file lets you read exactly what a model is told and answer in its
place. The module was tested that way with typical requests: a landing page from nothing (a media search, a preview,
then the page), a follow-up that reorders sections, adds an image and a view, and a recipe page made of the page's
fields, answered sloppily on purpose (JSON in a code fence with text around it, a component and a field that do not
exist, an `onclick` prop, HTML with `style` and `onclick`). The invented items were left out and counted, the unsafe
attributes were removed by the text format when rendering, and kept items kept their images and text formats.

## What leaves the site

Each request sends to the configured provider: the list of components with their descriptions, the names of the
theme's tokens, the title, type and language of the page, **the current layout of the page with its texts**, and the
conversation. When the model uses its tools: names and alternative texts of media it searched for, the rendered HTML
of its layout (with whatever the page's fields, views and blocks print there), and, if allowed, a picture of the
canvas. Field values of the page are not sent, except where they are typed into the layout. Whether that is
acceptable depends on your provider and content; a local provider (Ollama) keeps everything on your infrastructure.

## Limits

- No streaming: the answer comes when all steps are done, which can take a while with a preview and a check.
- The assistant cannot upload media or create entities other than through the layout, and it has no
  access to the browser's developer tools: its check is the rendered HTML and, if allowed, a picture.
- It returns the whole layout each time. On very large pages a model may shorten or drop parts: check the result, and
  undo if needed.
- The prompt grows with the number of components and their examples (about 32,000 characters for the demo theme).
- The conversation is kept in the browser until the editor is reloaded.
- An answer that is not valid JSON changes nothing; the chat says so.
