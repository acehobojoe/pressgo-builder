#!/usr/bin/env node

/**
 * PressGo MCP Server
 *
 * Exposes PressGo Builder functionality as MCP tools and resources.
 * Wraps WP-CLI commands — works locally or via SSH.
 *
 * Environment variables:
 *   PRESSGO_WP_PATH  — Path to WordPress root (local mode). Default: auto-detect.
 *   PRESSGO_SSH_HOST — SSH host alias for remote WordPress (e.g., "digitalocean").
 *                      When set, commands run via SSH instead of locally.
 *   PRESSGO_SSH_WP_PATH — WordPress path on the remote host.
 *                          Default: /var/www/wp.pressgo.app/htdocs
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { execFile, exec } from "node:child_process";
import { promisify } from "node:util";
import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const execFileAsync = promisify(execFile);
const execAsync = promisify(exec);

const __dirname = dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = join(__dirname, "..");

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const SSH_HOST = process.env.PRESSGO_SSH_HOST || "";
const SSH_WP_PATH =
  process.env.PRESSGO_SSH_WP_PATH || "/var/www/wp.pressgo.app/htdocs";
const LOCAL_WP_PATH = process.env.PRESSGO_WP_PATH || "";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Run a WP-CLI command, either locally or via SSH.
 * Returns { stdout, stderr }.
 */
async function wpCli(args, { timeout = 120_000 } = {}) {
  if (SSH_HOST) {
    // Remote mode — run over SSH.
    const wpCmd = `cd ${SSH_WP_PATH} && wp ${args.join(" ")} --allow-root`;
    const { stdout, stderr } = await execAsync(
      `ssh ${SSH_HOST} ${JSON.stringify(wpCmd)}`,
      { timeout, maxBuffer: 10 * 1024 * 1024 }
    );
    return { stdout, stderr };
  }

  // Local mode.
  const wpArgs = [...args];
  if (LOCAL_WP_PATH) {
    wpArgs.push(`--path=${LOCAL_WP_PATH}`);
  }
  const { stdout, stderr } = await execFileAsync("wp", wpArgs, {
    timeout,
    maxBuffer: 10 * 1024 * 1024,
  });
  return { stdout, stderr };
}

/**
 * Write content to a temporary file on the target (local or remote).
 * Returns the path to the temp file.
 */
async function writeTempJson(content) {
  const json = typeof content === "string" ? content : JSON.stringify(content);
  if (SSH_HOST) {
    const { stdout } = await execAsync(
      `ssh ${SSH_HOST} "mktemp /tmp/pressgo-config-XXXXXX.json"`,
      { timeout: 10_000 }
    );
    const tmpPath = stdout.trim();
    // Write via ssh stdin to avoid shell escaping issues.
    await execAsync(
      `echo ${JSON.stringify(json)} | ssh ${SSH_HOST} "cat > ${tmpPath}"`,
      { timeout: 10_000 }
    );
    return tmpPath;
  }
  // Local — write to /tmp.
  const { writeFile } = await import("node:fs/promises");
  const { tmpdir } = await import("node:os");
  const path = join(tmpdir(), `pressgo-config-${Date.now()}.json`);
  await writeFile(path, json, "utf-8");
  return path;
}

/**
 * Clean up a temp file.
 */
async function removeTempFile(path) {
  try {
    if (SSH_HOST) {
      await execAsync(`ssh ${SSH_HOST} "rm -f ${path}"`, { timeout: 5_000 });
    } else {
      const { unlink } = await import("node:fs/promises");
      await unlink(path);
    }
  } catch {
    // Ignore cleanup errors.
  }
}

// ---------------------------------------------------------------------------
// Section variant data (static reference from the generator)
// ---------------------------------------------------------------------------

const SECTION_VARIANTS = {
  hero: {
    default: "Centered text on dark gradient",
    split: "Text-left + image-right on light bg",
    image: "Full background image with dark overlay",
    video: "Centered text + video embed on light bg",
    gradient: "Colorful gradient bg with waves divider",
    minimal: "Clean white bg, centered text, no gradient",
  },
  stats: {
    default: "White cards with icons, overlaps hero",
    dark: "Dark gradient bg with colored counters",
    inline: "Minimal horizontal counter row with dividers",
  },
  social_proof: {
    default: "Industry pill badges on light bg",
    dark: "Industry pill badges on dark bg",
  },
  features: {
    default: "3-column card grid with accent borders",
    alternating: "Alternating text/image rows",
    minimal: "Clean icons with text, no cards",
    image_cards: "Image on top of each card",
    grid: "2-column card grid for 4+ features",
  },
  steps: {
    default: "Numbered circles on light bg cards",
    compact: "Numbered pill badges with divider",
    timeline: "Vertical timeline with connecting line",
  },
  results: {
    default: "Dark gradient with counter cards",
    bars: "Light bg with animated progress bars",
  },
  competitive_edge: {
    default: "Text + icon-list checklist",
    image: "Text + checkmarks left, image right",
    cards: "Benefit cards with icons in 3-col grid",
  },
  testimonials: {
    default: "3-column cards with star ratings",
    featured: "Single large quote + small cards",
    grid: "2-column card grid with avatars",
    minimal: "Centered quotes with dividers, no cards",
  },
  faq: {
    default: "Centered toggle accordion",
    split: "Header left, accordion right",
  },
  pricing: {
    default: "2-4 column plan cards with feature lists",
    compact: "Left-aligned cards, smaller price, bordered highlight",
  },
  logo_bar: {
    default: '"Trusted by" logo row',
    dark: "Dark bg logo row",
  },
  team: {
    default: "Photo + name + role + bio + social cards",
    compact: "Small photos, name + role only, no cards",
  },
  gallery: {
    default: "Image grid with lightbox",
    cards: "2-col image cards with optional captions",
  },
  newsletter: {
    default: "Email capture card with CTA",
    inline: "Gradient bar with headline + button",
  },
  cta_final: {
    default: "Gradient bar with centered text",
    card: "White card on light background",
    image: "Background image with dark overlay",
  },
  map: {
    default: "Google Maps embed with optional header",
  },
  footer: {
    default: "Multi-column dark footer with brand/links/contact",
    light: "White/light bg footer with colored icons",
  },
  blog: {
    default: "Recent posts grid (requires Elementor Pro)",
  },
  disclaimer: {
    default: "Small centered disclaimer text",
  },
};

// ---------------------------------------------------------------------------
// MCP Server
// ---------------------------------------------------------------------------

const server = new McpServer({
  name: "pressgo",
  version: "1.0.0",
});

// ── Tool: generate_page ─────────────────────────────────────────────────────

server.tool(
  "generate_page",
  "Generate an Elementor landing page from a text description. " +
    "Calls Claude AI to design the page, then builds Elementor JSON and " +
    "creates a WordPress draft page. Returns the post ID and URLs.",
  {
    prompt: z
      .string()
      .describe(
        "Text description of the page to generate (e.g., 'A landing page for a dog walking service in Portland')"
      ),
    title: z
      .string()
      .optional()
      .describe('Page title. Defaults to "Generated Landing Page".'),
  },
  async ({ prompt, title }) => {
    try {
      const args = ["pressgo", "generate", prompt];
      if (title) args.push(`--title=${title}`);

      const { stdout, stderr } = await wpCli(args, { timeout: 180_000 });
      const output = stdout + (stderr ? `\n${stderr}` : "");

      // Extract post ID and URLs from output.
      const postMatch = output.match(/Post ID:\s*(\d+)/);
      const viewMatch = output.match(/View:\s*(https?:\/\/\S+)/i) ||
        output.match(/view\s+(https?:\/\/\S+)/i);
      const editMatch = output.match(/edit\s+(https?:\/\/\S+)/i);

      let summary = "";
      if (postMatch) summary += `Post ID: ${postMatch[1]}\n`;
      if (viewMatch) summary += `View: ${viewMatch[1]}\n`;
      if (editMatch) summary += `Edit: ${editMatch[1]}\n`;

      return {
        content: [
          {
            type: "text",
            text: summary || output,
          },
        ],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${err.message}` }],
        isError: true,
      };
    }
  }
);

// ── Tool: build_from_config ─────────────────────────────────────────────────

server.tool(
  "build_from_config",
  "Build an Elementor page from a pre-made PressGo config JSON object. " +
    "Skips the AI call — uses the config directly to generate Elementor layout " +
    "and create a WordPress page.",
  {
    config: z
      .string()
      .describe(
        "JSON string of the PressGo config dict (colors, fonts, layout, sections, hero, features, etc.)"
      ),
    title: z
      .string()
      .optional()
      .describe('Page title. Defaults to "Generated Landing Page".'),
    dry_run: z
      .boolean()
      .optional()
      .describe(
        "If true, validates and generates but does not create the WordPress page."
      ),
  },
  async ({ config, title, dry_run }) => {
    let tmpPath;
    try {
      tmpPath = await writeTempJson(config);
      const args = ["pressgo", "generate", `--config=${tmpPath}`];
      if (title) args.push(`--title=${title}`);
      if (dry_run) args.push("--dry-run");
      args.push("--dump-config");

      const { stdout, stderr } = await wpCli(args, { timeout: 60_000 });
      return {
        content: [{ type: "text", text: stdout + (stderr ? `\n${stderr}` : "") }],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${err.message}` }],
        isError: true,
      };
    } finally {
      if (tmpPath) await removeTempFile(tmpPath);
    }
  }
);

// ── Tool: validate_config ───────────────────────────────────────────────────

server.tool(
  "validate_config",
  "Validate a PressGo config JSON without creating a page. " +
    "Returns the validated/sanitized config with defaults filled in, " +
    "plus section and widget counts.",
  {
    config: z
      .string()
      .describe("JSON string of the PressGo config dict to validate."),
  },
  async ({ config }) => {
    let tmpPath;
    try {
      tmpPath = await writeTempJson(config);
      const args = [
        "pressgo",
        "generate",
        `--config=${tmpPath}`,
        "--dry-run",
        "--dump-config",
      ];

      const { stdout, stderr } = await wpCli(args, { timeout: 30_000 });
      return {
        content: [{ type: "text", text: stdout + (stderr ? `\n${stderr}` : "") }],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${err.message}` }],
        isError: true,
      };
    } finally {
      if (tmpPath) await removeTempFile(tmpPath);
    }
  }
);

// ── Tool: preview_elements ──────────────────────────────────────────────────

server.tool(
  "preview_elements",
  "Generate the raw Elementor JSON elements from a config without creating a page. " +
    "Useful for inspecting the exact Elementor structure that would be produced.",
  {
    config: z
      .string()
      .describe("JSON string of the PressGo config dict."),
  },
  async ({ config }) => {
    let tmpPath;
    try {
      tmpPath = await writeTempJson(config);
      const args = [
        "pressgo",
        "generate",
        `--config=${tmpPath}`,
        "--dry-run",
        "--dump-elements",
      ];

      const { stdout, stderr } = await wpCli(args, { timeout: 30_000 });
      return {
        content: [{ type: "text", text: stdout + (stderr ? `\n${stderr}` : "") }],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${err.message}` }],
        isError: true,
      };
    } finally {
      if (tmpPath) await removeTempFile(tmpPath);
    }
  }
);

// ── Tool: test_connection ───────────────────────────────────────────────────

server.tool(
  "test_connection",
  "Test that WP-CLI is available and the PressGo plugin is active.",
  {},
  async () => {
    try {
      // Check WP-CLI + plugin status.
      const { stdout } = await wpCli(["plugin", "status", "pressgo-builder"], {
        timeout: 15_000,
      });
      return {
        content: [
          { type: "text", text: `Connection OK.\n${stdout.trim()}` },
        ],
      };
    } catch (err) {
      return {
        content: [
          {
            type: "text",
            text: `Connection failed: ${err.message}\n\nMake sure WP-CLI is installed and the PressGo plugin is active.`,
          },
        ],
        isError: true,
      };
    }
  }
);

// ── Tool: list_section_types ────────────────────────────────────────────────

server.tool(
  "list_section_types",
  "List all available PressGo section types and their layout variants. " +
    "Use this to understand what sections and variants can be used in a config.",
  {},
  async () => {
    const lines = [];
    for (const [section, variants] of Object.entries(SECTION_VARIANTS)) {
      lines.push(`## ${section}`);
      for (const [variant, desc] of Object.entries(variants)) {
        const label = variant === "default" ? "(default)" : variant;
        lines.push(`  - ${label}: ${desc}`);
      }
      lines.push("");
    }
    return {
      content: [
        {
          type: "text",
          text: `# PressGo Section Types\n\n${lines.join("\n")}\n\nTotal: ${Object.keys(SECTION_VARIANTS).length} section types, ${Object.values(SECTION_VARIANTS).reduce((sum, v) => sum + Object.keys(v).length, 0)} variants.`,
        },
      ],
    };
  }
);

// ── Resource: section-variants ──────────────────────────────────────────────

server.resource(
  "section-variants",
  "pressgo://section-variants",
  {
    description:
      "Complete reference of all PressGo section types and layout variants with descriptions.",
    mimeType: "application/json",
  },
  async () => ({
    contents: [
      {
        uri: "pressgo://section-variants",
        mimeType: "application/json",
        text: JSON.stringify(SECTION_VARIANTS, null, 2),
      },
    ],
  })
);

// ── Resource: config-schema ─────────────────────────────────────────────────

server.resource(
  "config-schema",
  "pressgo://config-schema",
  {
    description:
      "Config schema documentation — the structure of the JSON config that PressGo expects. " +
      "Includes required keys, color palette, fonts, layout settings, and section-specific fields.",
    mimeType: "application/json",
  },
  async () => {
    const schema = {
      description:
        "PressGo config dict schema. This JSON object is what the AI produces and the generator consumes.",
      required_top_level: ["colors", "fonts", "layout", "sections"],
      colors: {
        required: [
          "primary",
          "dark_bg",
          "light_bg",
          "white",
          "text_dark",
          "text_muted",
        ],
        optional_with_defaults: {
          primary_dark: "auto-darkened from primary",
          primary_light: "#E8F0FE",
          accent: "#00B418",
          accent_hover: "#009E15",
          text_light: "rgba(255,255,255,0.75)",
          gold: "#F59E0B",
          border: "rgba(0,0,0,0.06)",
        },
      },
      fonts: {
        heading: "Google Font name (default: Inter)",
        body: "Google Font name (default: Inter)",
      },
      layout: {
        boxed_width: "number, default 1200",
        section_padding: "number, default 100",
        card_radius: "number, default 16",
        button_radius: "number, default 10",
        card_shadow:
          "{ horizontal, vertical, blur, spread, color } — default: soft 24px blur",
      },
      sections:
        "Array of section type names in display order, e.g. ['hero', 'features', 'testimonials', 'cta_final', 'footer']",
      section_types: Object.fromEntries(
        Object.entries(SECTION_VARIANTS).map(([name, variants]) => [
          name,
          { variants: Object.keys(variants) },
        ])
      ),
      hero_example: {
        variant: "split",
        headline: "Your Headline Here",
        subheadline: "Supporting text",
        eyebrow: "CATEGORY",
        badge: "New",
        cta_primary: { text: "Get Started", url: "#" },
        cta_secondary: { text: "Learn More", url: "#" },
        image: "https://images.pexels.com/photos/...",
        trust_line: "Trusted by 10,000+ users",
      },
      features_example: {
        variant: "default",
        eyebrow: "FEATURES",
        headline: "Why Choose Us",
        items: [
          {
            icon: "fas fa-star",
            headline: "Feature Name",
            description: "Feature description text",
          },
        ],
      },
    };

    return {
      contents: [
        {
          uri: "pressgo://config-schema",
          mimeType: "application/json",
          text: JSON.stringify(schema, null, 2),
        },
      ],
    };
  }
);

// ── Resource: developer-reference ───────────────────────────────────────────

server.resource(
  "developer-reference",
  "pressgo://developer-reference",
  {
    description:
      "PressGo developer reference (CLAUDE.md) — architecture, generator details, " +
      "Elementor rules, responsive design patterns, and API documentation.",
    mimeType: "text/markdown",
  },
  async () => {
    try {
      const content = await readFile(join(PLUGIN_ROOT, "CLAUDE.md"), "utf-8");
      return {
        contents: [
          {
            uri: "pressgo://developer-reference",
            mimeType: "text/markdown",
            text: content,
          },
        ],
      };
    } catch {
      return {
        contents: [
          {
            uri: "pressgo://developer-reference",
            mimeType: "text/plain",
            text: "CLAUDE.md not found in plugin root.",
          },
        ],
      };
    }
  }
);

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error("PressGo MCP server failed to start:", err);
  process.exit(1);
});
