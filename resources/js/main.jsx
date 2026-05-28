import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

ReactDOM.createRoot(document.getElementById('app')).render(<App />);

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.getRegistrations()
      .then((registrations) => registrations.forEach((registration) => registration.unregister()))
      .catch(() => {});

    if ('caches' in window) {
      caches.keys()
        .then((keys) => keys
          .filter((key) => key.startsWith('arabina-pc'))
          .forEach((key) => caches.delete(key)))
        .catch(() => {});
    }
  });
}
