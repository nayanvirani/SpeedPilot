import React, { useState } from 'react';
import { BlockStack, Button, InlineStack, Text } from '@shopify/polaris';
import { api } from '../api';

function CodeBlock({ label, content }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        try {
            await navigator.clipboard.writeText(content);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access can be denied by the browser - the code is
            // still selectable/copyable by hand from the block below.
        }
    }

    return (
        <BlockStack gap="100">
            <InlineStack align="space-between" blockAlign="center">
                <Text as="span" fontWeight="medium">{label}</Text>
                <Button size="micro" onClick={copy}>{copied ? 'Copied' : 'Copy'}</Button>
            </InlineStack>
            <pre className="sp-mono sp-code-block">{content}</pre>
        </BlockStack>
    );
}

function FixFile({ file }) {
    const [showFullFile, setShowFullFile] = useState(false);
    const snippet = file.snippet;

    // A snippet only exists when the file actually has both an original and
    // a fixed version to diff - falls back to the full file otherwise (e.g.
    // a brand-new file with nothing to compare against).
    const usingSnippet = snippet && !showFullFile;
    const pasteContent = usingSnippet ? snippet.after : file.fixed;
    const referenceContent = usingSnippet ? snippet.before : file.original;

    return (
        <BlockStack gap="150">
            <Text as="span" tone="subdued">File: <span className="sp-mono">{file.asset_key}</span></Text>
            {usingSnippet && !snippet.unchanged && (
                <Text as="span" tone="subdued">
                    Find this around line {snippet.start_line} of the file{snippet.truncated_before || snippet.truncated_after ? ' (only the changed part is shown below, with a little surrounding text to help you locate it)' : ''}:
                </Text>
            )}
            <CodeBlock label={usingSnippet ? 'Find this...' : 'Original (full file)'} content={referenceContent} />
            <CodeBlock label={usingSnippet ? '...replace with this' : 'Paste this in (full file)'} content={pasteContent} />
            {snippet && (
                <Button size="micro" variant="tertiary" onClick={() => setShowFullFile((v) => !v)}>
                    {showFullFile ? 'Show just the changed part' : 'Show the whole file instead'}
                </Button>
            )}
        </BlockStack>
    );
}

/**
 * The "manual fix" half of the two-option choice every fix now offers:
 * this only ever calls a read-only fix-code endpoint - it can't write
 * anything to the theme, by construction, since the endpoints it calls
 * (audit-issues/{id}/fix-code, app-impacts/{id}/fix-code) don't accept a
 * write action at all.
 */
export default function FixCodeViewer({ fetchPath, label = 'Manual fix - view code' }) {
    const [code, setCode] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(false);

    async function load() {
        setOpen(true);
        if (code) return;
        setLoading(true);
        setError(null);
        try {
            const res = await api.get(fetchPath);
            setCode(res.code);
        } catch (e) {
            setError(e.body?.error || 'Could not compute this fix right now.');
        } finally {
            setLoading(false);
        }
    }

    if (!open) {
        return <Button size="micro" onClick={load}>{label}</Button>;
    }

    return (
        <BlockStack gap="200">
            <InlineStack align="space-between" blockAlign="center">
                <Text as="span" fontWeight="medium">Manual fix</Text>
                <Button size="micro" variant="tertiary" onClick={() => setOpen(false)}>Hide</Button>
            </InlineStack>
            {loading && <Text as="p" tone="subdued">Computing the fix from your live theme…</Text>}
            {error && <Text as="span" tone="critical">{error}</Text>}
            {code && (
                <BlockStack gap="300">
                    <Text as="p" tone="subdued">
                        SpeedPilot never touched your theme to make this - open Shopify admin &gt; Online
                        Store &gt; Themes &gt; Edit code, find the file below, locate the "Find this..." text,
                        and replace it with the code shown underneath. No need to touch the rest of the file.
                    </Text>
                    {code.files.map((file) => <FixFile key={file.asset_key} file={file} />)}
                    {code.truncated_count > 0 && (
                        <Text as="p" tone="subdued">
                            +{code.truncated_count} more file{code.truncated_count === 1 ? '' : 's'} would change
                            the same way - shown files are representative of the rest.
                        </Text>
                    )}
                </BlockStack>
            )}
        </BlockStack>
    );
}
