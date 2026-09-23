<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Single Item Cataloging | Priority 3</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 font-sans min-h-screen p-8">

    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md p-6">
        <h1 class="text-2xl font-bold text-slate-900 border-b pb-2 mb-6">Single-Item Cataloging Tool</h1>

        <!-- ISBN LOOKUP INPUT -->
        <div class="mb-6 bg-indigo-50 p-4 rounded-lg border border-indigo-100">
            <label for="isbn_input" class="block text-sm font-semibold text-indigo-900 mb-1">Lookup ISBN</label>
            <div class="flex gap-2">
                <input type="text" id="isbn_input" placeholder="e.g., 9780131103627" 
                       class="flex-1 border border-slate-300 rounded-lg p-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <button type="button" id="btn_fetch" 
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg flex items-center gap-2">
                    <span id="btn_text">Fetch Metadata</span>
                    <span id="spinner" class="hidden animate-spin">⌛</span>
                </button>
            </div>
            <div id="status_message" class="text-xs mt-2 font-medium"></div>
        </div>

        <!-- AUTO-FILLED FORM -->
        <form action="save_item.php" method="POST" class="space-y-4">
            <div class="flex gap-4 items-start">
                <!-- Cover Image Preview -->
                <div class="w-32 h-44 bg-slate-200 rounded-lg overflow-hidden flex items-center justify-center border text-slate-400 text-xs text-center p-2 flex-shrink-0" id="cover_wrapper">
                    <img id="cover_preview" src="" alt="Cover Preview" class="w-full h-full object-cover hidden">
                    <span id="cover_placeholder">No Cover Available</span>
                </div>
                <input type="hidden" name="cover_url" id="cover_url">

                <div class="flex-1 space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Generated Cote Reference</label>
                        <input type="text" name="cote" id="cote" readonly 
                               class="w-full bg-slate-100 border border-slate-300 rounded-md p-2 text-sm font-mono text-indigo-700 font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">ISBN</label>
                        <input type="text" name="isbn" id="isbn" readonly 
                               class="w-full bg-slate-100 border border-slate-300 rounded-md p-2 text-sm font-mono">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Title</label>
                <input type="text" name="title" id="title" required 
                       class="w-full border border-slate-300 rounded-md p-2 text-sm">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Author(s)</label>
                    <input type="text" name="author" id="author" 
                           class="w-full border border-slate-300 rounded-md p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Publisher</label>
                    <input type="text" name="publisher" id="publisher" 
                           class="w-full border border-slate-300 rounded-md p-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Publication Date / Year</label>
                <input type="text" name="published_date" id="published_date" 
                       class="w-full border border-slate-300 rounded-md p-2 text-sm">
            </div>

            <div class="pt-4 border-t">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2 px-4 rounded-lg">
                    Save to Database
                </button>
            </div>
        </form>
    </div>

    <script>
        const btnFetch = document.getElementById('btn_fetch');
        const btnText = document.getElementById('btn_text');
        const spinner = document.getElementById('spinner');
        const isbnInput = document.getElementById('isbn_input');
        const statusMsg = document.getElementById('status_message');

        async function fetchBookMetadata() {
            const rawIsbn = isbnInput.value.trim();
            if (!rawIsbn) {
                setStatus('Please enter an ISBN number.', 'text-rose-600');
                return;
            }

            setLoading(true);
            setStatus('Fetching metadata...', 'text-indigo-600');

            try {
                const response = await fetch(`api_isbn_lookup.php?isbn=${encodeURIComponent(rawIsbn)}`);
                const result = await response.json();

                if (result.success) {
                    const data = result.data;
                    document.getElementById('title').value = data.title || '';
                    document.getElementById('author').value = data.authors || '';
                    document.getElementById('publisher').value = data.publisher || '';
                    document.getElementById('published_date').value = data.published_date || '';
                    document.getElementById('isbn').value = rawIsbn;
                    document.getElementById('cote').value = data.cote || '';

                    // Cover preview
                    const coverPreview = document.getElementById('cover_preview');
                    const coverPlaceholder = document.getElementById('cover_placeholder');
                    const coverUrlInput = document.getElementById('cover_url');

                    if (data.cover_url) {
                        coverPreview.src = data.cover_url;
                        coverPreview.classList.remove('hidden');
                        coverPlaceholder.classList.add('hidden');
                        coverUrlInput.value = data.cover_url;
                    } else {
                        coverPreview.classList.add('hidden');
                        coverPlaceholder.classList.remove('hidden');
                        coverUrlInput.value = '';
                    }

                    setStatus('Metadata loaded successfully!', 'text-emerald-600');
                } else {
                    setStatus(result.message || 'Book not found.', 'text-rose-600');
                }
            } catch (err) {
                setStatus('Network or server error during lookup.', 'text-rose-600');
            } finally {
                setLoading(false);
            }
        }

        function setLoading(isLoading) {
            if (isLoading) {
                btnFetch.disabled = true;
                btnText.textContent = 'Searching...';
                spinner.classList.remove('hidden');
            } else {
                btnFetch.disabled = false;
                btnText.textContent = 'Fetch Metadata';
                spinner.classList.add('hidden');
            }
        }

        function setStatus(msg, colorClass) {
            statusMsg.textContent = msg;
            statusMsg.className = `text-xs mt-2 font-medium ${colorClass}`;
        }

        btnFetch.addEventListener('click', fetchBookMetadata);
        isbnInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                fetchBookMetadata();
            }
        });
    </script>
</body>
</html>