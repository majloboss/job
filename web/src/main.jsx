import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import App from './App';
import Login from './pages/Login';
import AiLab from './pages/AiLab';
import Modely from './pages/Modely';
import Naklady from './pages/Naklady';
import Zber from './pages/Zber';
import Ponuky from './pages/Ponuky';
import Documents from './pages/Documents';
import Preferences from './pages/Preferences';
import './index.css';

// Chranena cast appky: bez tokenu presmeruj na prihlasenie.
function Chranene({ children }) {
  return localStorage.getItem('token') ? children : <Navigate to="/login" replace />;
}

// Adminska cast. Je to len pohodlie pre pouzivatela — skutocne prava
// kontroluje server pri kazdom volani, nie tato podmienka.
function LenAdmin({ children }) {
  return localStorage.getItem('role') === 'admin'
    ? children
    : <Navigate to="/ponuky" replace />;
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={<Chranene><App /></Chranene>}>
          {/* Ponuky su to, preco appka existuje — patria na uvod. */}
          <Route index element={<Navigate to="/ponuky" replace />} />
          <Route path="ponuky"      element={<Ponuky />} />
          <Route path="dokumenty"   element={<Documents />} />
          <Route path="preferencie" element={<Preferences />} />
          <Route path="lab"         element={<LenAdmin><AiLab /></LenAdmin>} />
          <Route path="modely"      element={<LenAdmin><Modely /></LenAdmin>} />
          <Route path="naklady"     element={<LenAdmin><Naklady /></LenAdmin>} />
          <Route path="zber"        element={<LenAdmin><Zber /></LenAdmin>} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>
);
