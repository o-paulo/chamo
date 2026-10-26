const articleCards = Array.from(document.querySelectorAll(".article-card"));
const categoryButtons = Array.from(document.querySelectorAll("[data-article-category]"));
const articleQuery = document.querySelector("#article-query");
const articleCount = document.querySelector("#visible-article-count");
const emptyState = document.querySelector("#articles-empty");
const announcement = document.querySelector("#article-announcement");
const searchForm = document.querySelector("#article-search-form");

let activeCategory = "all";
const requestedCategory = new URLSearchParams(window.location.search).get("category");
const initialCategoryButton = categoryButtons.find((button) => button.dataset.articleCategory === requestedCategory);

function filterArticles() {
  const query = articleQuery.value.trim().toLocaleLowerCase("pt-BR");
  let visible = 0;

  for (const article of articleCards) {
    const matchesCategory = activeCategory === "all" || article.dataset.category === activeCategory;
    const matchesQuery = article.textContent.toLocaleLowerCase("pt-BR").includes(query);
    const isVisible = matchesCategory && matchesQuery;

    article.hidden = !isVisible;
    if (isVisible) visible += 1;
  }

  articleCount.textContent = String(visible);
  emptyState.hidden = visible !== 0;
  announcement.textContent = `${visible} ${visible === 1 ? "artigo encontrado" : "artigos encontrados"}.`;
}

for (const button of categoryButtons) {
  button.addEventListener("click", () => {
    activeCategory = button.dataset.articleCategory;

    for (const item of categoryButtons) {
      const isSelected = item === button;
      item.classList.toggle("is-selected", isSelected);
      item.setAttribute("aria-pressed", String(isSelected));
    }

    filterArticles();
  });
}

articleQuery.addEventListener("input", filterArticles);
searchForm.addEventListener("submit", event => {
  event.preventDefault();
  filterArticles();
});

for (const suggestion of document.querySelectorAll("[data-search-suggestion]")) {
  suggestion.addEventListener("click", () => {
    articleQuery.value = suggestion.dataset.searchSuggestion;
    filterArticles();
    articleQuery.focus();
  });
}

if (initialCategoryButton) {
  activeCategory = initialCategoryButton.dataset.articleCategory;
  for (const button of categoryButtons) {
    const isSelected = button === initialCategoryButton;
    button.classList.toggle("is-selected", isSelected);
    button.setAttribute("aria-pressed", String(isSelected));
  }
}

filterArticles();
