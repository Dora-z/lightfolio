const { createApp } = Vue;

createApp({
  data() {
    return {
      photos: [],
      groups: [],
      categories: [],
      saveStatus: "Ready",
      uploadLabel: "Upload photo",
      photoForm: this.emptyPhotoForm(),
      categoryForm: { originalId: "", id: "", name: "" },
      groupForm: this.emptyGroupForm(),
    };
  },
  computed: {
    categoryMap() {
      return window.LightfolioStore.getCategoryMap(this.categories);
    },
    groupMap() {
      return window.LightfolioStore.getGroupMap(this.groups);
    },
  },
  async mounted() {
    this.categories = await window.LightfolioStore.loadCategories();
    this.groups = await window.LightfolioStore.loadGroups();
    this.photos = await window.LightfolioStore.loadPhotos();
    this.resetPhotoForm();
    this.resetCategoryForm();
    this.resetGroupForm();
  },
  methods: {
    emptyPhotoForm() {
      return {
        id: "",
        title: "",
        group: "",
        meta: "",
        shotAt: "",
        camera: "",
        lens: "",
        focalLength: "",
        aperture: "",
        shutter: "",
        iso: "",
        url: "",
        previewUrl: "",
      };
    },
    emptyCategoryForm() {
      return { originalId: "", id: "", name: "" };
    },
    emptyGroupForm() {
      return { originalId: "", id: "", category: "", name: "", description: "", coverPhotoId: "" };
    },
    categoryLabel(categoryId) {
      return this.categoryMap[categoryId]?.name || categoryId || "";
    },
    groupLabel(groupId) {
      return this.groupMap[groupId]?.name || groupId || "";
    },
    previewSrc(photo) {
      return photo?.previewUrl || photo?.thumbnail || photo?.url || "";
    },
    formPreviewSrc() {
      return this.photoForm.previewUrl || this.photoForm.url || "";
    },
    fallbackCategoryId() {
      return this.categories[0]?.id || "portfolio";
    },
    fallbackGroupId() {
      return this.groups[0]?.id || "daily";
    },
    photosForGroup(groupId) {
      return this.photos.filter((photo) => photo.group === groupId);
    },
    groupCoverPhoto(groupId) {
      const group = this.groupMap[groupId];
      if (!group?.coverPhotoId) return null;

      return this.photos.find((photo) => photo.id === group.coverPhotoId && photo.group === groupId) || null;
    },
    isGroupCover(photo) {
      return this.groupMap[photo.group]?.coverPhotoId === photo.id;
    },
    moveItem(items, index, step) {
      const nextIndex = index + step;
      if (nextIndex < 0 || nextIndex >= items.length) return items;

      const nextItems = [...items];
      [nextItems[index], nextItems[nextIndex]] = [nextItems[nextIndex], nextItems[index]];

      return nextItems;
    },
    normalizeId(value) {
      return window.LightfolioStore.normalizeId(value);
    },
    resetPhotoForm() {
      this.photoForm = this.emptyPhotoForm();
      this.photoForm.group = this.fallbackGroupId();
      this.uploadLabel = "Upload photo";
    },
    resetCategoryForm() {
      this.categoryForm = this.emptyCategoryForm();
    },
    resetGroupForm() {
      this.groupForm = this.emptyGroupForm();
      this.groupForm.category = this.fallbackCategoryId();
    },
    async saveCategories() {
      const result = await window.LightfolioStore.saveCategories(this.categories, { requireServer: true });
      this.categories = result.categories;
      this.saveStatus = "Saved";
    },
    async saveGroups() {
      const result = await window.LightfolioStore.saveGroups(this.groups, { requireServer: true });
      this.groups = result.groups;
      this.saveStatus = "Saved";
    },
    async savePhotos() {
      const result = await window.LightfolioStore.savePhotos(this.photos, { requireServer: true });
      this.photos = result.photos;
      this.saveStatus = "Saved";
    },
    async moveCategory(index, step) {
      this.categories = this.moveItem(this.categories, index, step);
      try {
        await this.saveCategories();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async moveGroup(index, step) {
      this.groups = this.moveItem(this.groups, index, step);
      try {
        await this.saveGroups();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async movePhoto(index, step) {
      this.photos = this.moveItem(this.photos, index, step);
      try {
        await this.savePhotos();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async submitPhoto() {
      const photo = window.LightfolioStore.normalizePhotos([
        {
          id: this.photoForm.id || crypto.randomUUID(),
          title: this.photoForm.title.trim(),
          group: this.photoForm.group || this.fallbackGroupId(),
          meta: this.photoForm.meta.trim(),
          shotAt: this.photoForm.shotAt,
          camera: this.photoForm.camera,
          lens: this.photoForm.lens,
          focalLength: this.photoForm.focalLength,
          aperture: this.photoForm.aperture,
          shutter: this.photoForm.shutter,
          iso: this.photoForm.iso,
          url: this.photoForm.url.trim(),
          previewUrl: this.photoForm.previewUrl || this.photoForm.url.trim(),
        },
      ])[0];

      const index = this.photos.findIndex((item) => item.id === photo.id);
      if (index >= 0) this.photos[index] = photo;
      else this.photos.unshift(photo);

      try {
        await this.savePhotos();
        this.resetPhotoForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    editPhoto(id) {
      const photo = this.photos.find((item) => item.id === id);
      if (!photo) return;
      this.photoForm = { ...this.emptyPhotoForm(), ...window.LightfolioStore.normalizePhotoDetails(photo), ...photo };
    },
    async deletePhoto(id) {
      const shouldClearCover = this.groups.some((group) => group.coverPhotoId === id);
      this.photos = this.photos.filter((item) => item.id !== id);
      if (shouldClearCover) {
        this.groups = this.groups.map((group) => (group.coverPhotoId === id ? { ...group, coverPhotoId: "" } : group));
      }

      try {
        if (shouldClearCover) await this.saveGroups();
        await this.savePhotos();
        if (this.photoForm.id === id) this.resetPhotoForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async submitCategory() {
      const originalId = this.categoryForm.originalId;
      const id = originalId || this.normalizeId(this.categoryForm.id);
      const name = this.categoryForm.name.trim();

      if (!id || !name) {
        this.saveStatus = "Saved";
        return;
      }

      const exists = this.categories.some((category) => category.id === id && category.id !== originalId);
      if (exists) {
        this.saveStatus = "Saved";
        return;
      }

      if (originalId) this.categories = this.categories.map((category) => (category.id === originalId ? { id, name } : category));
      else this.categories.push({ id, name });

      try {
        await this.saveCategories();
        this.resetCategoryForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    editCategory(id) {
      const category = this.categories.find((item) => item.id === id);
      if (!category) return;
      this.categoryForm = { originalId: category.id, id: category.id, name: category.name };
    },
    async deleteCategory(id) {
      if (this.categories.length <= 1) {
        this.saveStatus = "Saved";
        return;
      }

      const nextCategory = this.categories.find((category) => category.id !== id)?.id;
      this.categories = this.categories.filter((category) => category.id !== id);
      this.groups = this.groups.map((group) => (group.category === id ? { ...group, category: nextCategory } : group));

      try {
        await this.saveCategories();
        await this.saveGroups();
        this.resetCategoryForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async submitGroup() {
      const originalId = this.groupForm.originalId;
      const id = originalId || this.normalizeId(this.groupForm.id);
      const category = this.groupForm.category || this.fallbackCategoryId();
      const name = this.groupForm.name.trim();
      const description = this.groupForm.description.trim();
      const coverPhotoId = this.photos.some((photo) => photo.id === this.groupForm.coverPhotoId && photo.group === id)
        ? this.groupForm.coverPhotoId
        : "";

      if (!id || !name) {
        this.saveStatus = "Saved";
        return;
      }

      const exists = this.groups.some((group) => group.id === id && group.id !== originalId);
      if (exists) {
        this.saveStatus = "Saved";
        return;
      }

      const group = { id, name, category, description, coverPhotoId };
      if (originalId) this.groups = this.groups.map((item) => (item.id === originalId ? group : item));
      else this.groups.push(group);

      try {
        await this.saveGroups();
        this.resetGroupForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    editGroup(id) {
      const group = this.groups.find((item) => item.id === id);
      if (!group) return;
      this.groupForm = { ...this.emptyGroupForm(), originalId: group.id, ...group };
    },
    async deleteGroup(id) {
      if (this.groups.length <= 1) {
        this.saveStatus = "Saved";
        return;
      }

      const nextGroup = this.groups.find((group) => group.id !== id)?.id;
      this.groups = this.groups.filter((group) => group.id !== id);
      this.photos = this.photos.map((photo) => (photo.group === id ? { ...photo, group: nextGroup } : photo));

      try {
        await this.saveGroups();
        await this.savePhotos();
        this.resetGroupForm();
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async setGroupCover(photo) {
      this.groups = this.groups.map((group) =>
        group.id === photo.group ? { ...group, coverPhotoId: photo.id } : group,
      );

      try {
        await this.saveGroups();
        if (this.groupForm.originalId === photo.group) this.groupForm.coverPhotoId = photo.id;
        this.saveStatus = "Saved";
      } catch {
        this.saveStatus = "Operation failed";
      }
    },
    async imageFileToWebp(file, quality = 0.82) {
      if (!file.type.startsWith("image/")) {
        throw new Error("Please choose an image file");
      }

      const bitmap = await this.decodeImage(file);
      const full = await this.renderWebp(bitmap, bitmap.width, bitmap.height, quality);
      const previewSize = this.fitInside(bitmap.width, bitmap.height, 900);
      const preview = await this.renderWebp(bitmap, previewSize.width, previewSize.height, 0.72);
      if (typeof bitmap.close === "function") bitmap.close();

      return {
        file: new File([full.blob], this.webpFilename(file.name), { type: "image/webp" }),
        previewFile: new File([preview.blob], this.webpFilename(file.name, "preview"), { type: "image/webp" }),
        width: full.width,
        height: full.height,
        previewWidth: preview.width,
        previewHeight: preview.height,
        originalSize: file.size,
        webpSize: full.blob.size,
        previewSize: preview.blob.size,
      };
    },
    async renderWebp(source, width, height, quality) {
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;

      const context = canvas.getContext("2d", { alpha: true });
      if (!context) throw new Error("Canvas is unavailable");

      context.drawImage(source, 0, 0, width, height);
      const blob = await this.canvasToBlob(canvas, "image/webp", quality);
      if (blob.type !== "image/webp") throw new Error("WebP export is not supported");

      return { blob, width, height };
    },
    fitInside(width, height, maxSide) {
      const scale = Math.min(1, maxSide / Math.max(width, height));
      return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
      };
    },
    async decodeImage(file) {
      if (window.createImageBitmap) {
        try {
          return await createImageBitmap(file, { imageOrientation: "from-image" });
        } catch {
          return await createImageBitmap(file);
        }
      }

      return new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);
        image.onload = () => {
          URL.revokeObjectURL(url);
          resolve(image);
        };
        image.onerror = () => {
          URL.revokeObjectURL(url);
          reject(new Error("Image decode failed"));
        };
        image.src = url;
      });
    },
    canvasToBlob(canvas, type, quality) {
      return new Promise((resolve, reject) => {
        canvas.toBlob(
          (blob) => {
            if (blob) resolve(blob);
            else reject(new Error("Image compression failed"));
          },
          type,
          quality,
        );
      });
    },
    webpFilename(name, suffix = "") {
      const baseName = String(name || "photo")
        .replace(/\.[^.]+$/, "")
        .replace(/[^a-zA-Z0-9_-]+/g, "-")
        .replace(/^[-_]+|[-_]+$/g, "")
        .slice(0, 50);

      return `${baseName || "photo"}${suffix ? `-${suffix}` : ""}.webp`;
    },
    formatBytes(bytes) {
      if (!Number.isFinite(bytes) || bytes <= 0) return "0 KB";
      if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
      return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },
    photoInfo(photo) {
      return window.LightfolioStore.getPhotoInfo(photo);
    },
    applyPhotoMetadata(metadata) {
      const details = window.LightfolioStore.normalizePhotoDetails(metadata || {});
      this.photoForm = {
        ...this.photoForm,
        ...details,
      };
    },
    async extractPhotoMetadata(file) {
      const fallbackData = await this.parseJpegExif(file);

      try {
        const data = window.exifr?.parse
          ? await window.exifr.parse(file, [
          "Make",
          "Model",
          "Lens",
          "LensModel",
          "LensInfo",
          "LensMake",
          "FocalLength",
          "FocalLengthIn35mmFormat",
          "FNumber",
          "ExposureTime",
          "ISO",
          "ISOSpeedRatings",
          "DateTimeOriginal",
          "CreateDate",
          "ModifyDate",
          "Orientation",
        ])
          : {};

        return this.mapExifToPhotoDetails(this.mergeExifData(fallbackData, data || {}));
      } catch {
        return this.mapExifToPhotoDetails(fallbackData);
      }
    },
    mergeExifData(...sources) {
      const merged = {};

      for (const source of sources) {
        for (const [key, value] of Object.entries(source || {})) {
          if (value !== undefined && value !== null && value !== "") merged[key] = value;
        }
      }

      return merged;
    },
    async parseJpegExif(file) {
      if (!file) return {};

      try {
        const buffer = await file.arrayBuffer();
        const view = new DataView(buffer);
        if (view.byteLength < 4 || view.getUint16(0, false) !== 0xffd8) return {};

        let offset = 2;
        while (offset + 4 <= view.byteLength) {
          if (view.getUint8(offset) !== 0xff) break;

          const marker = view.getUint8(offset + 1);
          const size = view.getUint16(offset + 2, false);
          if (size < 2 || offset + 2 + size > view.byteLength) break;

          if (marker === 0xe1 && this.readAscii(view, offset + 4, 6) === "Exif\0\0") {
            return this.parseTiffExif(view, offset + 10, offset + 2 + size);
          }

          offset += 2 + size;
        }
      } catch {
        return {};
      }

      return {};
    },
    parseTiffExif(view, tiffStart, segmentEnd) {
      if (tiffStart + 8 > segmentEnd) return {};

      const byteOrder = this.readAscii(view, tiffStart, 2);
      const littleEndian = byteOrder === "II";
      if (!littleEndian && byteOrder !== "MM") return {};
      if (view.getUint16(tiffStart + 2, littleEndian) !== 42) return {};

      const firstIfdOffset = view.getUint32(tiffStart + 4, littleEndian);
      const ifd0 = this.readTiffIfd(view, tiffStart, segmentEnd, firstIfdOffset, littleEndian);
      const exifOffset = ifd0[0x8769]?.value;
      const exif = exifOffset ? this.readTiffIfd(view, tiffStart, segmentEnd, exifOffset, littleEndian) : {};

      return {
        Make: ifd0[0x010f]?.value,
        Model: ifd0[0x0110]?.value,
        ModifyDate: ifd0[0x0132]?.value,
        Orientation: ifd0[0x0112]?.value,
        ExposureTime: exif[0x829a]?.raw || exif[0x829a]?.value,
        FNumber: exif[0x829d]?.raw || exif[0x829d]?.value,
        ISO: exif[0x8827]?.value || exif[0x8833]?.value,
        DateTimeOriginal: exif[0x9003]?.value,
        CreateDate: exif[0x9004]?.value,
        FocalLength: exif[0x920a]?.raw || exif[0x920a]?.value,
        FocalLengthIn35mmFormat: exif[0xa405]?.value,
        LensInfo: exif[0xa432]?.raw || exif[0xa432]?.value,
        LensMake: exif[0xa433]?.value,
        LensModel: exif[0xa434]?.value,
      };
    },
    readTiffIfd(view, tiffStart, segmentEnd, ifdOffset, littleEndian) {
      const ifdStart = tiffStart + Number(ifdOffset || 0);
      if (ifdStart < tiffStart || ifdStart + 2 > segmentEnd) return {};

      const count = view.getUint16(ifdStart, littleEndian);
      const entries = {};

      for (let index = 0; index < count; index += 1) {
        const entryOffset = ifdStart + 2 + index * 12;
        if (entryOffset + 12 > segmentEnd) break;

        const tag = view.getUint16(entryOffset, littleEndian);
        const type = view.getUint16(entryOffset + 2, littleEndian);
        const valueCount = view.getUint32(entryOffset + 4, littleEndian);
        const typeSize = this.tiffTypeSize(type);
        if (!typeSize || valueCount < 0) continue;

        const byteLength = typeSize * valueCount;
        const valueOffset = byteLength <= 4 ? entryOffset + 8 : tiffStart + view.getUint32(entryOffset + 8, littleEndian);
        if (valueOffset < tiffStart || valueOffset + byteLength > segmentEnd) continue;

        const parsed = this.readTiffValue(view, valueOffset, type, valueCount, littleEndian);
        entries[tag] = parsed;
      }

      return entries;
    },
    tiffTypeSize(type) {
      return {
        1: 1,
        2: 1,
        3: 2,
        4: 4,
        5: 8,
        7: 1,
        9: 4,
        10: 8,
      }[type] || 0;
    },
    readTiffValue(view, offset, type, count, littleEndian) {
      if (type === 2) {
        return { value: this.readAscii(view, offset, count).replace(/\0+$/, "").trim() };
      }

      const values = [];
      const raws = [];

      for (let index = 0; index < count; index += 1) {
        const cursor = offset + index * this.tiffTypeSize(type);
        if (type === 1 || type === 7) values.push(view.getUint8(cursor));
        if (type === 3) values.push(view.getUint16(cursor, littleEndian));
        if (type === 4) values.push(view.getUint32(cursor, littleEndian));
        if (type === 9) values.push(view.getInt32(cursor, littleEndian));
        if (type === 5 || type === 10) {
          const numerator = type === 5 ? view.getUint32(cursor, littleEndian) : view.getInt32(cursor, littleEndian);
          const denominator = type === 5 ? view.getUint32(cursor + 4, littleEndian) : view.getInt32(cursor + 4, littleEndian);
          raws.push(`${numerator}/${denominator}`);
          values.push(denominator ? numerator / denominator : NaN);
        }
      }

      return {
        raw: raws.length === 1 ? raws[0] : raws,
        value: values.length === 1 ? values[0] : values,
      };
    },
    readAscii(view, offset, length) {
      let text = "";
      const end = Math.min(view.byteLength, offset + length);
      for (let index = offset; index < end; index += 1) {
        text += String.fromCharCode(view.getUint8(index));
      }
      return text;
    },
    mapExifToPhotoDetails(exif) {
      const camera = this.combineCameraName(exif.Make, exif.Model);
      const lens =
        this.cleanExifText(exif.LensModel) ||
        this.cleanExifText(exif.Lens) ||
        this.cleanExifText(exif.LensModel) ||
        this.cleanExifText(exif.LensInfo) ||
        [this.cleanExifText(exif.LensMake), this.cleanExifText(exif.LensModel)].filter(Boolean).join(" ").trim();
      const shotAt = this.formatExifDate(exif.DateTimeOriginal || exif.CreateDate || exif.ModifyDate);

      if (!this.isReliableExif({ ...exif, camera, lens, shotAt })) {
        return this.emptyPhotoDetails();
      }

      return {
        shotAt,
        camera,
        lens,
        focalLength: this.formatFocalLength(exif.FocalLength || exif.FocalLengthIn35mmFormat),
        aperture: this.formatAperture(exif.FNumber),
        shutter: this.formatShutter(exif.ExposureTime),
        iso: this.formatIso(exif.ISO || exif.ISOSpeedRatings),
      };
    },
    emptyPhotoDetails() {
      return {
        shotAt: "",
        camera: "",
        lens: "",
        focalLength: "",
        aperture: "",
        shutter: "",
        iso: "",
      };
    },
    isReliableExif(exif) {
      const hasGear = Boolean(this.cleanExifText(exif.camera) || this.cleanExifText(exif.lens));
      const hasShotAt = Boolean(this.cleanExifText(exif.shotAt));

      if (!hasGear && !hasShotAt) return false;

      const shutter = this.parseExifNumber(exif.ExposureTime);
      if (Number.isFinite(shutter) && (shutter < 1 / 32000 || shutter > 120)) return false;

      const aperture = this.parseExifNumber(exif.FNumber);
      if (Number.isFinite(aperture) && (aperture < 0.7 || aperture > 64)) return false;

      const focalLength = this.parseExifNumber(exif.FocalLength || exif.FocalLengthIn35mmFormat);
      if (Number.isFinite(focalLength) && (focalLength < 0.5 || focalLength > 3000)) return false;

      return true;
    },
    combineCameraName(make, model) {
      const cleanMake = this.cleanExifText(make);
      const cleanModel = this.cleanExifText(model);
      if (!cleanMake) return cleanModel;
      if (!cleanModel) return cleanMake;
      if (cleanModel.toLowerCase().startsWith(cleanMake.toLowerCase())) return cleanModel;
      return `${cleanMake} ${cleanModel}`;
    },
    cleanExifText(value) {
      return String(value || "")
        .replace(/\u0000/g, "")
        .trim();
    },
    formatExifDate(value) {
      if (!value) return "";
      if (value instanceof Date && Number.isFinite(value.getTime())) {
        const year = value.getFullYear();
        const month = String(value.getMonth() + 1).padStart(2, "0");
        const day = String(value.getDate()).padStart(2, "0");
        const hour = String(value.getHours()).padStart(2, "0");
        const minute = String(value.getMinutes()).padStart(2, "0");
        const second = String(value.getSeconds()).padStart(2, "0");
        return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
      }

      return String(value || "")
        .trim()
        .replace(/^(\d{4}):(\d{2}):(\d{2})/, "$1-$2-$3")
        .replace("T", " ");
    },
    formatFocalLength(value) {
      const number = this.parseExifNumber(value);
      if (!Number.isFinite(number) || number <= 0) return "";
      return `${this.trimNumber(number)}mm`;
    },
    formatAperture(value) {
      const number = this.parseExifNumber(value);
      if (!Number.isFinite(number) || number <= 0) return "";
      return `f/${this.trimNumber(number)}`;
    },
    formatShutter(value) {
      const number = this.parseExifNumber(value);
      if (!Number.isFinite(number) || number <= 0) return "";
      if (number >= 1) return `${this.trimNumber(number)}s`;

      const denominator = Math.round(1 / number);
      if (!Number.isFinite(denominator) || denominator <= 0) return "";
      return `1/${denominator}s`;
    },
    formatIso(value) {
      const number = this.parseExifNumber(Array.isArray(value) ? value[0] : value);
      if (!Number.isFinite(number) || number <= 0) return "";
      return `ISO ${Math.round(number)}`;
    },
    parseExifNumber(value) {
      if (value === null || value === undefined || value === "") return NaN;

      if (typeof value === "number") return value;

      if (Array.isArray(value)) {
        for (const item of value) {
          const parsed = this.parseExifNumber(item);
          if (Number.isFinite(parsed)) return parsed;
        }
        return NaN;
      }

      if (typeof value === "object") {
        const numerator = value.numerator ?? value.num ?? value[0];
        const denominator = value.denominator ?? value.denom ?? value[1];
        if (numerator !== undefined && denominator !== undefined) {
          const top = this.parseExifNumber(numerator);
          const bottom = this.parseExifNumber(denominator);
          if (Number.isFinite(top) && Number.isFinite(bottom) && bottom !== 0) return top / bottom;
        }

        if (typeof value.toString === "function") {
          return this.parseExifNumber(value.toString());
        }

        return NaN;
      }

      const text = String(value).trim();
      if (!text) return NaN;

      const fraction = text.match(/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/);
      if (fraction) {
        const top = Number(fraction[1]);
        const bottom = Number(fraction[2]);
        if (Number.isFinite(top) && Number.isFinite(bottom) && bottom !== 0) return top / bottom;
      }

      const numeric = Number(text);
      return Number.isFinite(numeric) ? numeric : NaN;
    },
    trimNumber(value) {
      return Number(value).toFixed(Number.isInteger(value) ? 0 : 1).replace(/\.0$/, "");
    },
    async readJsonResponse(response) {
      const text = await response.text();
      try {
        return text ? JSON.parse(text) : {};
      } catch {
        throw new Error(`Server returned non-JSON response (${response.status})`);
      }
    },
    async uploadPhoto(event) {
      const file = event.target.files?.[0];
      if (!file) return;

      this.uploadLabel = "Processing WebP...";
      this.saveStatus = "Saved";

      try {
        const metadata = await this.extractPhotoMetadata(file);
        this.applyPhotoMetadata(metadata);

        const compressed = await this.imageFileToWebp(file);
        const formData = new FormData();
        formData.append("photo", compressed.file);
        formData.append("preview", compressed.previewFile);

        this.uploadLabel = `Uploading WebP - ${this.formatBytes(compressed.webpSize)}`;
        this.saveStatus = `Compressed ${this.formatBytes(compressed.originalSize)} -> ${this.formatBytes(compressed.webpSize)}, preview ${this.formatBytes(compressed.previewSize)}`;

        const response = await fetch("./api/upload.php", {
          method: "POST",
          headers: { "X-CSRF-Token": window.LightfolioStore.csrfToken() },
          body: formData,
        });
        const payload = await this.readJsonResponse(response);
        if (!response.ok) throw new Error(payload.error || "Upload failed");

        this.photoForm.url = payload.url;
        this.photoForm.previewUrl = payload.previewUrl || payload.url;
        this.uploadLabel = `Uploaded WebP - ${payload.width}x${payload.height}`;
        this.saveStatus = `Image uploaded. Preview ${this.formatBytes(compressed.previewSize)}`;
      } catch (error) {
        this.uploadLabel = "Upload failed, retry";
        this.saveStatus = error.message || "Upload failed";
      } finally {
        event.target.value = "";
      }
    },
    downloadBackup(format) {
      window.location.href = `./api/backup.php?format=${encodeURIComponent(format)}`;
    },
    async restoreBackup(event) {
      const file = event.target.files?.[0];
      if (!file) return;

      const formData = new FormData();
      formData.append("backup", file);

      try {
        const response = await fetch("./api/backup.php", {
          method: "POST",
          headers: { "X-CSRF-Token": window.LightfolioStore.csrfToken() },
          body: formData,
        });
        const payload = await this.readJsonResponse(response);
        if (!response.ok || !payload.ok) throw new Error(payload.error || "Restore failed");

        window.LightfolioStore.reset();
        this.categories = await window.LightfolioStore.loadCategories();
        this.groups = await window.LightfolioStore.loadGroups();
        this.photos = await window.LightfolioStore.loadPhotos();
        this.resetPhotoForm();
        this.resetCategoryForm();
        this.resetGroupForm();
        this.saveStatus = "Saved";
      } catch {
        this.saveStatus = "Operation failed";
      }

      event.target.value = "";
    },
  },
}).mount("#admin-app");
