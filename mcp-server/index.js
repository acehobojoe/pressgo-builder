#!/usr/bin/env node

/**
 * PressGo MCP Server — edit PressGo landing pages from Claude Code.
 *
 * Environment variables:
 *   PRESSGO_URL — WordPress site URL (e.g. https://your-site.com)
 *   PRESSGO_KEY — Direct Access API key (from PressGo Settings)
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const PRESSGO_URL = process.env.PRESSGO_URL;
const PRESSGO_KEY = process.env.PRESSGO_KEY;

if (!PRESSGO_URL || !PRESSGO_KEY) {
	console.error('Error: PRESSGO_URL and PRESSGO_KEY environment variables are required.');
	console.error('Set them in your MCP server config.');
	process.exit(1);
}

const API_BASE = PRESSGO_URL.replace(/\/$/, '') + '/wp-json/pressgo/v1';

/**
 * Make an authenticated request to the PressGo REST API.
 */
async function apiRequest(method, path, body) {
	const url = API_BASE + path;
	const opts = {
		method,
		headers: {
			'X-PressGo-Key': PRESSGO_KEY,
			'Content-Type': 'application/json',
		},
	};
	if (body) {
		opts.body = JSON.stringify(body);
	}

	const res = await fetch(url, opts);
	const text = await res.text();

	let data;
	try {
		data = JSON.parse(text);
	} catch {
		if (!res.ok) {
			throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
		}
		return text;
	}

	if (!res.ok) {
		const msg = data.message || data.data?.message || JSON.stringify(data);
		throw new Error(`HTTP ${res.status}: ${msg}`);
	}

	return data;
}

// Create MCP server.
const server = new McpServer({
	name: 'pressgo',
	version: '1.0.0',
});

// --- Tools ---

server.registerTool(
	'list_pages',
	{
		description:
			'List all PressGo pages. Returns page IDs, titles, status, URLs, and version timestamps.',
		inputSchema: {},
	},
	async () => {
		const pages = await apiRequest('GET', '/pages');
		return {
			content: [
				{
					type: 'text',
					text: JSON.stringify(pages, null, 2),
				},
			],
		};
	}
);

server.registerTool(
	'get_page_config',
	{
		description:
			'Get the full config JSON for a PressGo page. Returns colors, fonts, layout, and all section data.',
		inputSchema: {
			page_id: z.number().describe('The WordPress post ID of the page'),
		},
	},
	async ({ page_id }) => {
		const data = await apiRequest('GET', `/pages/${page_id}/config`);
		return {
			content: [
				{
					type: 'text',
					text: JSON.stringify(data, null, 2),
				},
			],
		};
	}
);

server.registerTool(
	'update_page_config',
	{
		description:
			'Update a PressGo page config. Validates the config, regenerates all Elementor JSON, flushes caches, and bumps the version (triggering iframe reload in WP admin). Send the FULL config object — this is a complete replace, not a patch.',
		inputSchema: {
			page_id: z.number().describe('The WordPress post ID of the page'),
			config: z
				.record(z.any())
				.describe(
					'The full PressGo config object (colors, fonts, layout, sections, and section data)'
				),
		},
	},
	async ({ page_id, config }) => {
		const data = await apiRequest('PUT', `/pages/${page_id}/config`, { config });
		return {
			content: [
				{
					type: 'text',
					text: JSON.stringify(data, null, 2),
				},
			],
		};
	}
);

server.registerTool(
	'create_page',
	{
		description:
			'Create a new PressGo page from a config JSON. The page is created as a draft with full Elementor data.',
		inputSchema: {
			title: z.string().describe('The page title'),
			config: z
				.record(z.any())
				.describe(
					'The full PressGo config object (colors, fonts, layout, sections, and section data)'
				),
		},
	},
	async ({ title, config }) => {
		const data = await apiRequest('POST', '/pages', { title, config });
		return {
			content: [
				{
					type: 'text',
					text: JSON.stringify(data, null, 2),
				},
			],
		};
	}
);

server.registerTool(
	'get_schema',
	{
		description:
			'Get the PressGo config schema. Use this to understand the structure of config objects — all section types, variants, required fields, and examples.',
		inputSchema: {},
	},
	async () => {
		const schema = await apiRequest('GET', '/schema');
		return {
			content: [
				{
					type: 'text',
					text: JSON.stringify(schema, null, 2),
				},
			],
		};
	}
);

// --- Start ---

async function main() {
	const transport = new StdioServerTransport();
	await server.connect(transport);
	console.error('PressGo MCP server running on stdio');
}

main().catch((err) => {
	console.error('Fatal error:', err);
	process.exit(1);
});
