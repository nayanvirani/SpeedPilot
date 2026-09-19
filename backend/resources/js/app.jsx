import '@shopify/polaris/build/esm/styles.css';
import '../css/app.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import { AppProvider } from '@shopify/polaris';
import enTranslations from '@shopify/polaris/locales/en.json';
import { BrowserRouter } from 'react-router-dom';
import App from './App';

const container = document.getElementById('app');
const root = createRoot(container);

root.render(
    <React.StrictMode>
        <AppProvider i18n={enTranslations}>
            <BrowserRouter>
                <App />
            </BrowserRouter>
        </AppProvider>
    </React.StrictMode>
);
