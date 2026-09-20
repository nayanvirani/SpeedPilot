import React from 'react';
import { BlockStack, InlineStack, Text } from '@shopify/polaris';

const CATEGORY_LABEL = {
    core_web_vitals: 'Core Web Vitals',
    images: 'Images',
    javascript: 'JavaScript',
    css: 'CSS',
    third_party: 'Third-party',
    theme: 'Theme',
};

const CATEGORY_ORDER = ['core_web_vitals', 'images', 'javascript', 'css', 'third_party', 'theme'];

function barClass(score) {
    if (score >= 90) return 'sp-subscore-bar--good';
    if (score >= 50) return 'sp-subscore-bar--warn';
    return 'sp-subscore-bar--critical';
}

export default function CategoryScores({ scores }) {
    if (!scores || Object.keys(scores).length === 0) {
        return null;
    }

    return (
        <BlockStack gap="300">
            <Text as="h3" variant="headingSm"><span className="sp-heading">Score breakdown</span></Text>
            <BlockStack gap="200">
                {CATEGORY_ORDER.filter((key) => scores[key] !== undefined).map((key) => (
                    <div key={key} className="sp-subscore-row">
                        <InlineStack align="space-between" blockAlign="center">
                            <Text as="span">{CATEGORY_LABEL[key]}</Text>
                            <Text as="span" fontWeight="semibold">{scores[key]}</Text>
                        </InlineStack>
                        <div className="sp-subscore-track">
                            <div className={`sp-subscore-bar ${barClass(scores[key])}`} style={{ width: `${scores[key]}%` }} />
                        </div>
                    </div>
                ))}
            </BlockStack>
        </BlockStack>
    );
}
