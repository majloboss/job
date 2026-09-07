import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import App from './App';
import Login from './pages/Login';
import AiLab from './pages/AiLab';
import Documents from './pages/Documents';
import Preferences from './pages/Preferences';
import './index.css';

// Chranena cast appky: bez tokenu presmeruj na prihlasenie.
function Chranene({ children }) {
  return localStorage.getItem('token') ? children : <Navigate to="/login" replace />;
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={<Chranene><App /></Chranene>}>
          <Route index element={<Navigate to="/lab" replace />} />
          <Route path="lab"         element={<AiLab />} />
          <Route path="dokumenty"   element={<Documents />} />
          <Route path="preferencie" element={<Preferences />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>
);
