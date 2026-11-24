=== Mosh AI: Chatbot & Content Writer ===
Contributors: samayun1
Tags: ai, chatbot, content generator, openai, ai writer
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbots trained on your content, an AI writer for posts and products, AI images, AI forms and a knowledge base.

== Description ==

Mosh AI adds a chatbot, an AI writer and AI images to WordPress, using your own AI provider key. You stay in control: the AI writes, you decide what gets published.

* **Chatbots**: unlimited bots, each with its own model, instructions, colors, start message and context size. Embed with [mosh_ai_chatbot id="default"] or show one as a floating chat bubble on every page.
* **Knowledge base (embeddings)**: sync your posts, pages and products, or add custom facts, so chatbots answer from your own content. Vectors are stored in your own database.
* **Providers**: OpenAI, Anthropic (Claude), Google Gemini, OpenRouter, and Others: any OpenAI-compatible API, such as DeepSeek, Groq, Mistral, xAI, Together, Perplexity, Qwen or a local Ollama / LM Studio server. Keys can also be set in wp-config.php: MOSHCW_OPENAI_KEY, MOSHCW_ANTHROPIC_KEY, MOSHCW_GOOGLE_KEY, MOSHCW_OPENROUTER_KEY.
* **Playground**: try any model, system prompt, temperature and max tokens.
* **Article writer**: full articles (title, excerpt, HTML body) saved as drafts for you to review.
* **Image generator**: images straight into the Media Library, with alt text filled in.
* **AI forms**: build forms with fields and a prompt template, embed with [mosh_ai_form id="..."].
* **Editor and WooCommerce assistant**: suggestions for titles, excerpts, meta descriptions, tags, content and product descriptions in the block editor, the classic editor and the product editor. You insert what you like.
* **Meta descriptions**: a field on every post and page, printed in the head (skipped when an SEO plugin is active).
* **Conversations**: chatbot conversations saved and browsable in wp-admin (can be turned off).
* **Usage and limits**: requests and tokens by day, model and feature. Daily limits per visitor and per user, a per-minute limit, and optional OpenAI moderation.

All AI features stay locked until you add an API key, so nothing contacts an AI service by accident.

= Developer hooks =

Filters: moshcw_models, moshcw_query_args, moshcw_query_result, moshcw_show_popup, moshcw_editor_post_types.
JavaScript: window.MoshCW.boot() re-scans the page for chatbots and forms.

== External services ==

This plugin connects to third-party AI services, but only the ones you configure with your own API key (or URL). Nothing is sent before that.

= OpenAI =
Used for chat, writing, image generation, embeddings (knowledge base) and optional moderation, when an OpenAI key is set. Sent: the prompt or chat message, the chatbot instructions, relevant knowledge-base excerpts, and for embeddings the text of synced content. Sent each time a feature is used.
Terms: https://openai.com/policies/terms-of-use/ . Privacy: https://openai.com/policies/privacy-policy/

= Anthropic (Claude) =
Used for chat and writing when an Anthropic key is set. Sent: the prompt or chat message, the chatbot instructions and relevant knowledge-base excerpts.
Terms: https://www.anthropic.com/legal/commercial-terms . Privacy: https://www.anthropic.com/legal/privacy

= Google Gemini =
Used for chat, writing, image generation and embeddings when a Google AI key is set. Sent: the same data as above.
Terms: https://ai.google.dev/gemini-api/terms . Privacy: https://policies.google.com/privacy

= OpenRouter =
Used for chat and writing when an OpenRouter key is set. Sent: the same data as above, routed to the model you pick.
Terms: https://openrouter.ai/terms . Privacy: https://openrouter.ai/privacy

= Others (OpenAI-compatible APIs) =
If you set a custom API URL (for example DeepSeek, Groq, Mistral, xAI, Together, Perplexity, Qwen, or your own Ollama or LM Studio server), the same data is sent to that URL. The terms and privacy policy of the service you choose apply.

== Installation ==

1. Install from Plugins > Add New, or upload the zip and activate.
2. Open Mosh AI in the admin menu and add an API key under Settings (Gemini has a free tier).
3. Add a chatbot to a page with the shortcode, or turn on the floating chat bubble on the Home tab.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. Add a key from OpenAI, Anthropic, Google Gemini or OpenRouter, or connect any OpenAI-compatible service under Others.

= Where is my data stored? =

Settings, chatbots, usage logs, saved conversations and knowledge-base vectors are stored in your own WordPress database. API keys are never shown again in full after saving.

= Does the AI change my site by itself? =

No. Mosh AI only writes text and images. Articles are saved as drafts, and editor suggestions are inserted only when you click them.

= Does it store visitor conversations? =

Only if "Save chat conversations" is on (it is by default, and you can turn it off in Settings). Mention it in your privacy policy if you keep it on. Old logs are deleted automatically after the retention period you choose.

= What happens when I delete the plugin? =

Your data is kept unless you turn on "Delete all Mosh AI data when the plugin is deleted" in Settings.

== Changelog ==

= 1.0.0 =
* First release: chatbots, knowledge base, playground, article and image generator, AI forms, editor and WooCommerce assistant, meta descriptions, conversations, usage limits.
