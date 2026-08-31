self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch(() => {
            // If the network fails (no internet), return the last cached page
            // Your JS above will then see the 'offline' event and show the overlay
            return caches.match(event.request) || caches.match('index.php');
        })
    );
});