import type { BodyFormat, BodyValidationLabels, BodyValidationResult } from './types/global';

const VAR_PATTERN = /\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g;

function resolveBodyFormat(protocol: string, contentType?: string): BodyFormat {
  if (protocol === 'soap') {
    return 'xml';
  }

  const ct = String(contentType ?? '').toLowerCase();
  if (ct.includes('xml') || ct.includes('soap')) {
    return 'xml';
  }

  return 'json';
}

function maskVariablesForJson(text: string): string {
  return String(text).replace(VAR_PATTERN, (match) => JSON.stringify(match));
}

function maskVariablesForXml(text: string): { masked: string; tokens: string[] } {
  const tokens: string[] = [];
  const masked = String(text).replace(VAR_PATTERN, (match) => {
    const token = `___ASVAR${tokens.length}___`;
    tokens.push(match);

    return token;
  });

  return { masked, tokens };
}

function restoreXmlTokens(text: string, tokens: string[]): string {
  return tokens.reduce(
    (out, original, index) => out.split(`___ASVAR${index}___`).join(original),
    text,
  );
}

function validateJson(body: string): BodyValidationResult {
  if (!body || !String(body).trim()) {
    return { valid: true, with_variables: false, message: 'empty' };
  }

  const hasVariables = VAR_PATTERN.test(body);
  VAR_PATTERN.lastIndex = 0;

  try {
    JSON.parse(body);

    return {
      valid: true,
      with_variables: hasVariables,
      message: hasVariables ? 'valid_json_with_variables' : 'valid_json',
    };
  } catch {
    try {
      JSON.parse(maskVariablesForJson(body));

      return { valid: true, with_variables: true, message: 'valid_json_with_variables' };
    } catch (e) {
      return {
        valid: false,
        with_variables: false,
        message: e instanceof Error ? e.message : String(e),
      };
    }
  }
}

function validateXml(body: string): BodyValidationResult {
  if (!body || !String(body).trim()) {
    return { valid: true, with_variables: false, message: 'empty' };
  }

  const hasVariables = VAR_PATTERN.test(body);
  VAR_PATTERN.lastIndex = 0;
  const parser = new DOMParser();
  let doc = parser.parseFromString(body, 'text/xml');

  if (!doc.querySelector('parsererror')) {
    return {
      valid: true,
      with_variables: hasVariables,
      message: hasVariables ? 'valid_xml_with_variables' : 'valid_xml',
    };
  }

  const masked = maskVariablesForXml(body);
  doc = parser.parseFromString(masked.masked, 'text/xml');
  if (!doc.querySelector('parsererror')) {
    return { valid: true, with_variables: true, message: 'valid_xml_with_variables' };
  }

  const err = doc.querySelector('parsererror');

  return {
    valid: false,
    with_variables: false,
    message: err?.textContent?.trim() ?? 'Invalid XML.',
  };
}

function formatJson(body: string): string {
  if (!body || !String(body).trim()) {
    return body;
  }

  const parsed = JSON.parse(maskVariablesForJson(body));

  return `${JSON.stringify(parsed, null, 2)}\n`;
}

function escapeXml(value: string): string {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatXmlDocument(node: Node, depth: number): string {
  const indent = '  '.repeat(depth);

  if (node.nodeType === Node.TEXT_NODE) {
    const text = node.textContent?.trim() ?? '';

    return text ? escapeXml(text) : '';
  }

  if (node.nodeType !== Node.ELEMENT_NODE) {
    return '';
  }

  const element = node as Element;
  const tag = element.nodeName;
  let attrs = '';
  for (let i = 0; i < element.attributes.length; i += 1) {
    const attr = element.attributes[i]!;
    attrs += ` ${attr.name}="${escapeXml(attr.value)}"`;
  }

  const childNodes = Array.from(element.childNodes).filter(
    (child) => child.nodeType !== Node.TEXT_NODE || (child.textContent?.trim() ?? '') !== '',
  );

  if (!childNodes.length) {
    return `${indent}<${tag}${attrs} />\n`;
  }

  if (childNodes.length === 1 && childNodes[0]!.nodeType === Node.TEXT_NODE) {
    return `${indent}<${tag}${attrs}>${escapeXml(childNodes[0]!.textContent?.trim() ?? '')}</${tag}>\n`;
  }

  const inner = childNodes.map((child) => formatXmlDocument(child, depth + 1)).join('');

  return `${indent}<${tag}${attrs}>\n${inner}${indent}</${tag}>\n`;
}

function formatXml(body: string): string {
  if (!body || !String(body).trim()) {
    return body;
  }

  const masked = maskVariablesForXml(body);
  const parser = new DOMParser();
  const doc = parser.parseFromString(masked.masked, 'text/xml');
  const parserError = doc.querySelector('parsererror');
  if (parserError) {
    throw new Error(parserError.textContent?.trim() || 'Invalid XML.');
  }

  const formatted = formatXmlDocument(doc.documentElement, 0);
  const withDecl = `<?xml version="1.0" encoding="UTF-8"?>\n${formatted}\n`;

  return restoreXmlTokens(withDecl, masked.tokens);
}

function validate(body: string, format: BodyFormat): BodyValidationResult {
  return format === 'xml' ? validateXml(body) : validateJson(body);
}

function formatBodyContent(body: string, bodyFormat: BodyFormat): string {
  return bodyFormat === 'xml' ? formatXml(body) : formatJson(body);
}

function formatDisplay(body: string, bodyFormat: BodyFormat): string {
  if (!body || !String(body).trim()) {
    return body || '';
  }

  try {
    return formatBodyContent(body, bodyFormat);
  } catch {
    return body;
  }
}

function messageLabel(result: BodyValidationResult, labels: BodyValidationLabels = {}): string {
  if (!result.valid) {
    return result.message;
  }

  return labels[result.message] ?? result.message;
}

window.ApiStudioBodyTools = {
  resolveBodyFormat,
  validate,
  format: formatBodyContent,
  formatDisplay,
  messageLabel,
};
