import { useState } from 'react';
import { Dashboard } from './components/Dashboard';
import { LoginForm } from './components/LoginForm';
import { getToken } from './lib/auth';

function App() {
  const [loggedIn, setLoggedIn] = useState(() => getToken() !== null);

  if (!loggedIn) {
    return <LoginForm onLoggedIn={() => setLoggedIn(true)} />;
  }

  return <Dashboard onSessionExpired={() => setLoggedIn(false)} />;
}

export default App;
