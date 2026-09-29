import React from 'react';
import { BlockStack, Card, InlineGrid, Text } from '@shopify/polaris';

const BUCKETS = [
    { key: 'oversized', label: 'Oversized', match: (t) => t.startsWith('Oversized image:') },
    { key: 'missing_dimensions', label: 'Missing dimensions', match: (t) => t.startsWith('Missing width/height:') },
    { key: 'missing_alt', label: 'Missing ALT', match: (t) => t.startsWith('Missing alt text:') },
    { key: 'modern_format', label: 'Old format (PNG/JPEG)', match: (t) => t.startsWith('Serve next-gen format:') },
    { key: 'lazy_load', label: 'Not lazy-loaded', match: (t) => t.startsWith('Lazy-load candidate:') },
    { key: 'large_gif', label: 'Large GIFs', match: (t) => t.startsWith('Large GIF:') },
];

// The aggregate view the source competitor list asks for ("1,284 images
// scanned, Oversized 84, Missing ALT 16, ...") - built entirely from issues
// domAnalyzer.js already emits (category: 'image'), just grouped and
// summed here rather than left as one flat list a merchant has to eyeball.
export default function ImageHealthCenter({ issues }) {
    const imageIssues = (issues ?? []).filter((i) => i.category === 'image');

    if (imageIssues.length === 0) {
        return null;
    }

    const counts = BUCKETS.map((b) => ({
        ...b,
        count: imageIssues.filter((i) => b.match(i.title ?? '')).length,
    })).filter((b) => b.count > 0);

    // Only "oversized" issues carry a real wasted_bytes figure (the gap
    // between downloaded and displayed size) - other buckets (missing alt,
    // missing dimensions, old format) have no comparable byte estimate.
    const potentialSavingsBytes = imageIssues.reduce(
        (sum, i) => sum + (i.meta?.evidence?.wasted_bytes ?? 0),
        0,
    );

    return (
        <Card>
            <BlockStack gap="300">
                <Text as="h3" variant="headingSm">
                    <span className="sp-heading">Image Health · {imageIssues.length} issue{imageIssues.length === 1 ? '' : 's'} found</span>
                </Text>
                <InlineGrid columns={{ xs: 2, sm: 3 }} gap="300">
                    {counts.map((b) => (
                        <BlockStack key={b.key} gap="050">
                            <Text as="span" variant="headingLg">{b.count}</Text>
                            <Text as="span" tone="subdued">{b.label}</Text>
                        </BlockStack>
                    ))}
                </InlineGrid>
                {potentialSavingsBytes > 0 && (
                    <Text as="p" tone="subdued">
                        Potential savings: {(potentialSavingsBytes / 1024 / 1024).toFixed(1)} MB
                    </Text>
                )}
            </BlockStack>
        </Card>
    );
}
