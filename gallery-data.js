(function () {
  const photosStorageKey = "lightfolio.photos";
  const groupsStorageKey = "lightfolio.groups";
  const categoriesStorageKey = "lightfolio.categories";
  const photosApiPath = "./api/photos.php";
  const groupsApiPath = "./api/groups.php";
  const categoriesApiPath = "./api/categories.php";

  const defaultCategories = [
    { id: "portfolio", name: "作品" },
    { id: "life", name: "生活" },
  ];

  const defaultGroups = [
    { id: "daily", name: "日常记录", category: "life", description: "随手捕捉的光线、街角与瞬间。", coverPhotoId: "" },
    { id: "travel", name: "旅行片段", category: "portfolio", description: "按旅程整理的一组照片。", coverPhotoId: "" },
  ];

  const defaultPhotos = [];

  function normalizeId(value) {
    return String(value || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_-]+/g, "-")
      .replace(/^[-_]+|[-_]+$/g, "")
      .slice(0, 40);
  }

  function normalizeCategories(value) {
    if (!Array.isArray(value)) return [...defaultCategories];

    const seen = new Set();
    const categories = value
      .map((category) => {
        const name = String(category?.name || "").trim();
        const id = normalizeId(category?.id || name);
        return { id, name };
      })
      .filter((category) => {
        if (!category.id || !category.name || seen.has(category.id)) return false;
        seen.add(category.id);
        return true;
      });

    return categories.length ? categories : [...defaultCategories];
  }

  function getCategories() {
    try {
      const stored = localStorage.getItem(categoriesStorageKey);
      return stored ? normalizeCategories(JSON.parse(stored)) : [...defaultCategories];
    } catch {
      return [...defaultCategories];
    }
  }

  function getCategoryMap(categories) {
    return normalizeCategories(categories).reduce((map, category) => {
      map[category.id] = category;
      return map;
    }, {});
  }

  function normalizeGroups(value) {
    const categories = getCategoryMap(getCategories());
    const fallbackCategory = Object.keys(categories)[0] || "portfolio";
    if (!Array.isArray(value)) return [...defaultGroups];

    const seen = new Set();
    const groups = value
      .map((group) => {
        const name = String(group?.name || "").trim();
        const id = normalizeId(group?.id || name);
        const category = categories[group?.category] ? group.category : fallbackCategory;
        const description = String(group?.description || "").trim();
        const coverPhotoId = String(group?.coverPhotoId || "").trim();
        return { id, name, category, description, coverPhotoId };
      })
      .filter((group) => {
        if (!group.id || !group.name || seen.has(group.id)) return false;
        seen.add(group.id);
        return true;
      });

    return groups.length ? groups : [...defaultGroups];
  }

  function getGroups() {
    try {
      const stored = localStorage.getItem(groupsStorageKey);
      return stored ? normalizeGroups(JSON.parse(stored)) : [...defaultGroups];
    } catch {
      return [...defaultGroups];
    }
  }

  function getGroupMap(groups) {
    return normalizeGroups(groups).reduce((map, group) => {
      map[group.id] = group;
      return map;
    }, {});
  }

  function normalizePhotos(value) {
    const groups = getGroupMap(getGroups());
    const fallbackGroup = Object.keys(groups)[0] || "daily";

    if (!Array.isArray(value)) return [...defaultPhotos];
    return value
      .filter((photo) => photo && photo.title && photo.url)
      .map((photo) => {
        const legacyGroup = photo.group || photo.category;
        const url = normalizeImageUrl(String(photo.url).trim());
        return {
          id: photo.id || crypto.randomUUID(),
          title: String(photo.title).trim(),
          group: groups[legacyGroup] ? legacyGroup : fallbackGroup,
          ...normalizePhotoDetails(photo),
          url,
          previewUrl: normalizePreviewUrl(url, photo.previewUrl || photo.thumbnail),
        };
      });
  }

  function normalizePhotoDetails(photo) {
    return {
      meta: normalizeText(photo?.meta, 120),
      shotAt: normalizeShotAt(photo?.shotAt),
      camera: normalizeText(photo?.camera, 80),
      lens: normalizeText(photo?.lens, 80),
      focalLength: normalizeText(photo?.focalLength, 24),
      aperture: normalizeText(photo?.aperture, 24),
      shutter: normalizeText(photo?.shutter, 24),
      iso: normalizeText(photo?.iso, 24),
    };
  }

  function normalizeText(value, maxLength = 120) {
    return String(value || "")
      .trim()
      .slice(0, maxLength);
  }

  function normalizeShotAt(value) {
    if (value instanceof Date && Number.isFinite(value.getTime())) {
      return formatDateTime(value);
    }

    const text = String(value || "").trim();
    if (!text) return "";

    const normalized = text.replace("T", " ");
    const match = normalized.match(/^(\d{4})[:/-](\d{2})[:/-](\d{2})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (!match) return normalized;

    const [, year, month, day, hour = "", minute = "", second = ""] = match;
    if (!hour) return `${year}-${month}-${day}`;

    return `${year}-${month}-${day} ${hour}:${minute}:${second || "00"}`;
  }

  function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    const hour = String(date.getHours()).padStart(2, "0");
    const minute = String(date.getMinutes()).padStart(2, "0");
    const second = String(date.getSeconds()).padStart(2, "0");
    return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
  }

  function getPhotoInfo(photo) {
    const details = normalizePhotoDetails(photo || {});
    const gear = [details.camera, details.lens].filter(Boolean).join(" / ");
    const exposure = [details.focalLength, details.aperture, details.shutter, details.iso].filter(Boolean).join(" · ");
    const secondary = [details.shotAt, gear, exposure, details.meta].filter(Boolean);

    return {
      ...details,
      gear,
      exposure,
      summary: secondary.join(" · "),
      hasAny: secondary.length > 0,
    };
  }

  function normalizePreviewUrl(url, previewUrl) {
    const normalizedPreview = normalizeImageUrl(String(previewUrl || "").trim());
    const inferredPreview = previewUrlFor(url);

    if (!normalizedPreview || normalizedPreview === url) return inferredPreview;
    return normalizedPreview;
  }

  function previewUrlFor(url) {
    const normalized = normalizeImageUrl(String(url || "").trim());
    const match = normalized.match(/^\.\/uploads\/([^/]+)\.webp$/i);
    if (!match) return normalized;

    return `./uploads/previews/${match[1]}-preview.webp`;
  }

  function normalizeImageUrl(url) {
    const proxyMatch = url.match(/image\.php\?file=([^&#]+)/i);
    if (!proxyMatch) return url;

    return `./uploads/${decodeURIComponent(proxyMatch[1])}`;
  }

  async function loadCategories() {
    try {
      const response = await fetch(categoriesApiPath, { cache: "no-store" });
      if (!response.ok) throw new Error("API unavailable");
      const categories = normalizeCategories(await response.json());
      localStorage.setItem(categoriesStorageKey, JSON.stringify(categories));
      return categories;
    } catch {
      return getCategories();
    }
  }

  async function saveCategories(categories, options = {}) {
    const normalized = normalizeCategories(categories);
    return saveJson(categoriesApiPath, normalized, categoriesStorageKey, "categories", options);
  }

  async function loadGroups() {
    try {
      const response = await fetch(groupsApiPath, { cache: "no-store" });
      if (!response.ok) throw new Error("API unavailable");
      const groups = normalizeGroups(await response.json());
      localStorage.setItem(groupsStorageKey, JSON.stringify(groups));
      return groups;
    } catch {
      return getGroups();
    }
  }

  async function saveGroups(groups, options = {}) {
    const normalized = normalizeGroups(groups);
    return saveJson(groupsApiPath, normalized, groupsStorageKey, "groups", options);
  }

  function getPhotos() {
    try {
      const stored = localStorage.getItem(photosStorageKey);
      return stored ? normalizePhotos(JSON.parse(stored)) : [...defaultPhotos];
    } catch {
      return [...defaultPhotos];
    }
  }

  async function loadPhotos() {
    try {
      const response = await fetch(photosApiPath, { cache: "no-store" });
      if (!response.ok) throw new Error("API unavailable");
      return normalizePhotos(await response.json());
    } catch {
      return getPhotos();
    }
  }

  async function savePhotos(photos, options = {}) {
    const normalized = normalizePhotos(photos);
    return saveJson(photosApiPath, normalized, photosStorageKey, "photos", options);
  }

  async function saveJson(path, data, storageKey, resultKey, options = {}) {
    try {
      const response = await fetch(path, {
        method: "POST",
        headers: secureHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) throw new Error(`API unavailable: ${response.status}`);
      localStorage.setItem(storageKey, JSON.stringify(data));
      return { mode: "server", [resultKey]: data };
    } catch {
      if (options.requireServer) throw new Error("Save failed");
      localStorage.setItem(storageKey, JSON.stringify(data));
      return { mode: "local", [resultKey]: data };
    }
  }

  function secureHeaders() {
    const headers = { "Content-Type": "application/json" };
    const csrfToken = window.LightfolioConfig?.csrfToken;

    if (csrfToken) headers["X-CSRF-Token"] = csrfToken;

    return headers;
  }

  function csrfToken() {
    return window.LightfolioConfig?.csrfToken || "";
  }

  window.LightfolioStore = {
    defaultPhotos,
    defaultGroups,
    defaultCategories,
    normalizeId,
    normalizeCategories,
    normalizeGroups,
    normalizePhotos,
    normalizePhotoDetails,
    getCategoryMap,
    getGroupMap,
    getPhotoInfo,
    normalizeImageUrl,
    getCategories,
    loadCategories,
    saveCategories,
    getGroups,
    loadGroups,
    saveGroups,
    getPhotos,
    loadPhotos,
    savePhotos,
    csrfToken,
    reset() {
      localStorage.removeItem(photosStorageKey);
      localStorage.removeItem(groupsStorageKey);
      localStorage.removeItem(categoriesStorageKey);
    },
  };
})();
