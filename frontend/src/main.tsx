import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import './index.css';

const container = document.getElementById('root');
if (!container) throw new Error('Root element missing from index.html');

createRoot(container).render(
  <StrictMode>
    {/* basename "/" — the SPA is served from the domain root by Apache. */}
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
