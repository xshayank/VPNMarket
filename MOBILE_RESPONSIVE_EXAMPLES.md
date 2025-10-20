# Mobile-Responsive Implementation Examples

## Before and After Comparison

### 1. Reseller Dashboard - Metric Cards

**Before (Desktop Only):**
```html
<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
  <div class="bg-blue-50 p-4 rounded-lg">
    <div class="text-sm">موجودی</div>
    <div class="text-2xl font-bold">1,000,000 تومان</div>
  </div>
  <!-- More cards... -->
</div>
```

**After (Mobile-Responsive):**
```html
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
  <div class="bg-blue-50 p-3 md:p-4 rounded-lg">
    <div class="text-xs md:text-sm">موجودی</div>
    <div class="text-lg md:text-2xl font-bold break-words">1,000,000 تومان</div>
  </div>
  <!-- More cards... -->
</div>
```

**Mobile View (< 640px):**
- Cards stack vertically (1 column)
- Smaller text (text-xs, text-lg)
- Reduced padding (p-3)
- Tighter gaps (gap-3)

**Tablet View (640px - 1024px):**
- 2 columns side-by-side
- Medium text sizes
- Standard padding

**Desktop View (≥ 1024px):**
- 4 columns in a row
- Full text sizes
- Full padding

---

### 2. Configs Index Table

**Before (Desktop Only):**
```html
<table class="w-full">
  <thead>
    <tr>
      <th>نام کاربری</th>
      <th>محدودیت ترافیک</th>
      <th>مصرف شده</th>
      <th>تاریخ انقضا</th>
      <th>وضعیت</th>
      <th>عملیات</th>
    </tr>
  </thead>
  <!-- Rows would overflow on mobile -->
</table>
```

**After (Mobile-Responsive):**
```html
<div class="overflow-x-auto">
  <table class="w-full min-w-[640px]">
    <thead>
      <tr>
        <th class="text-xs md:text-sm">نام کاربری</th>
        <th class="hidden sm:table-cell">محدودیت ترافیک</th>
        <th class="hidden md:table-cell">مصرف شده</th>
        <th class="hidden sm:table-cell">تاریخ انقضا</th>
        <th>وضعیت</th>
        <th>عملیات</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <div class="font-medium">user123</div>
          <!-- Mobile-only: inline key info -->
          <div class="text-xs sm:hidden">
            <div>محدودیت: 50 GB</div>
            <div class="md:hidden">مصرف: 23 GB</div>
            <div>انقضا: 2025-02-15</div>
          </div>
        </td>
        <td class="hidden sm:table-cell">50 GB</td>
        <td class="hidden md:table-cell">23 GB</td>
        <td class="hidden sm:table-cell">2025-02-15</td>
        <td>...</td>
        <td>...</td>
      </tr>
    </tbody>
  </table>
</div>
```

**Mobile View (< 640px):**
- Only shows: نام کاربری, وضعیت, عملیات
- Key metrics displayed inline under username
- Horizontal scroll available if needed
- Smaller text throughout

**Tablet View (640px - 768px):**
- Shows محدودیت ترافیک and تاریخ انقضا columns
- Hides مصرف شده column
- Standard text sizes

**Desktop View (≥ 768px):**
- Shows all columns
- Full visibility and spacing

---

### 3. Action Buttons

**Before (Desktop Only):**
```html
<div class="flex gap-4">
  <a href="..." class="px-4 py-2 bg-blue-600">ایجاد کانفیگ</a>
  <a href="..." class="px-4 py-2 bg-gray-600">مشاهده همه</a>
  <button class="px-4 py-2 bg-green-600">به‌روزرسانی</button>
</div>
```

**After (Mobile-Responsive):**
```html
<div class="flex flex-col sm:flex-row gap-3 md:gap-4">
  <a href="..." class="w-full sm:w-auto px-4 py-3 md:py-2 bg-blue-600 text-center">
    ایجاد کانفیگ
  </a>
  <a href="..." class="w-full sm:w-auto px-4 py-3 md:py-2 bg-gray-600 text-center">
    مشاهده همه
  </a>
  <form class="w-full sm:w-auto">
    <button class="w-full px-4 py-3 md:py-2 bg-green-600">
      به‌روزرسانی
    </button>
  </form>
</div>
```

**Mobile View:**
- Buttons stack vertically
- Full width for easy tapping
- Taller height (py-3) for 48px touch target
- Centered text

**Desktop View:**
- Buttons inline horizontally
- Auto-width based on content
- Standard height (py-2)

---

### 4. Form Inputs

**Before (Desktop Only):**
```html
<div class="mb-4">
  <label class="block text-sm mb-2">محدودیت ترافیک (GB)</label>
  <input type="number" class="w-full rounded-md" />
</div>
<div class="mb-4">
  <label class="block text-sm mb-2">مدت اعتبار (روز)</label>
  <input type="number" class="w-full rounded-md" />
</div>
```

**After (Mobile-Responsive):**
```html
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
  <div>
    <label class="block text-xs md:text-sm mb-2">محدودیت ترافیک (GB)</label>
    <input type="number" class="w-full h-12 md:h-10 rounded-md text-sm md:text-base" />
  </div>
  <div>
    <label class="block text-xs md:text-sm mb-2">مدت اعتبار (روز)</label>
    <input type="number" class="w-full h-12 md:h-10 rounded-md text-sm md:text-base" />
  </div>
</div>
```

**Mobile View:**
- Fields stack vertically (1 column)
- Taller inputs (h-12, 48px) for easier tapping
- Smaller labels (text-xs)
- Smaller input text (text-sm)

**Desktop View:**
- Fields side-by-side (2 columns)
- Standard input height (h-10, 40px)
- Standard label size (text-sm)
- Standard input text (text-base)

---

### 5. Navigation Bar

**Before (Desktop Only):**
```html
<nav class="flex items-center py-3 overflow-x-auto">
  <a class="flex items-center px-3 py-2 text-sm">
    <svg class="w-5 h-5 ml-2">...</svg>
    <span>داشبورد</span>
  </a>
  <!-- More links... -->
</nav>
```

**After (Mobile-Responsive):**
```html
<nav class="flex items-center py-2 md:py-3 overflow-x-auto scrollbar-hide">
  <a class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm whitespace-nowrap">
    <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2">...</svg>
    <span>داشبورد</span>
  </a>
  <!-- More links... -->
</nav>
```

**Mobile View:**
- Compact navigation items
- Smaller icons (w-4 h-4)
- Smaller text (text-xs)
- Horizontal scroll enabled
- Hidden scrollbar (scrollbar-hide)
- Reduced padding

**Desktop View:**
- Standard-sized items
- Normal icons and text
- Standard padding

---

## Touch Target Sizes

All interactive elements follow mobile best practices:

| Element | Mobile Size | Desktop Size | Note |
|---------|------------|--------------|------|
| Buttons | h-12 (48px) | h-10 (40px) | Meets 44px minimum |
| Input fields | h-12 (48px) | h-10 (40px) | Comfortable typing |
| Nav links | py-2 (32px total) | py-2 (32px total) | With icon padding |
| Table action buttons | min-h-[40px] | standard | Vertical stack on mobile |
| Checkboxes | w-5 h-5 (20px) | w-4 h-4 (16px) | Easier to tap |

---

## Container Spacing

Consistent spacing reduction on mobile:

| Property | Mobile | Tablet | Desktop |
|----------|--------|--------|---------|
| Page padding | py-6, px-3 | py-12, px-6 | py-12, px-8 |
| Card padding | p-3 | p-4 | p-6 |
| Grid gaps | gap-3 | gap-4 | gap-6 |
| Button gaps | gap-3 | gap-4 | gap-4 |

---

## Typography Scale

Consistent text sizing across screens:

| Element | Mobile | Desktop | Purpose |
|---------|--------|---------|---------|
| Card titles | text-base | text-lg | Readability |
| Metric values | text-lg | text-2xl | Hierarchy |
| Labels | text-xs | text-sm | Space efficiency |
| Body text | text-sm | text-base | Content |
| Table headers | text-xs | text-sm | Compact display |

---

## Special Features

### 1. Scrollbar Hide Utility

Custom CSS utility for clean horizontal scrolling:

```css
.scrollbar-hide {
  -ms-overflow-style: none;  /* IE and Edge */
  scrollbar-width: none;      /* Firefox */
}
.scrollbar-hide::-webkit-scrollbar {
  display: none;              /* Chrome, Safari, Opera */
}
```

Used in navigation bar for seamless horizontal scrolling without visible scrollbar.

### 2. Word Breaking

Applied to prevent layout issues with long values:
- Order prices: `break-words`
- Usernames: `break-words max-w-[150px] md:max-w-none`
- Subscription URLs: Wrapped in scrollable containers

### 3. Conditional Column Display

Tables use intelligent column hiding:
- Essential columns always visible
- Secondary info hidden on small screens
- Key metrics shown inline on mobile
- Maintains functionality without clutter

---

## RTL (Right-to-Left) Support

All changes maintain proper RTL support:
- Text alignment: `text-right` preserved
- Flex direction: Uses `flex-row-reverse` where needed
- Margins: Uses `ml-` (margin-left) which becomes margin-right in RTL
- Icons: Positioned correctly with RTL context
- Persian text: Fully supported with proper rendering

---

## Performance Considerations

The implementation is performant:
- **No JavaScript required** for responsive behavior
- **Pure CSS/Tailwind** for all responsive features
- **Minimal CSS footprint**: Only standard Tailwind classes
- **No external dependencies**: Uses built-in browser features
- **Fast rendering**: No layout recalculations needed
