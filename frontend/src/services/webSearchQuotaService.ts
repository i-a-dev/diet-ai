export interface WebSearchQuota {
  monthlyLimit: number;
  usedCount: number;
  remainingCount: number;
  resetDate: string;
  isPremium: boolean;
}

const WEB_SEARCH_USAGE_KEY = "dietai.webSearchUsage.v1";
const DEFAULT_MONTHLY_LIMIT = 20;

interface StoredUsage {
  yearMonth: string;
  usedCount: number;
}

function getYearMonthLabel(date = new Date()): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  return `${year}-${month}`;
}

function getResetDate(date = new Date()): string {
  const reset = new Date(date.getFullYear(), date.getMonth() + 1, 1);
  return reset.toISOString().slice(0, 10);
}

function loadUsage(): StoredUsage {
  const raw = localStorage.getItem(WEB_SEARCH_USAGE_KEY);
  const currentMonth = getYearMonthLabel();

  if (!raw) {
    return { yearMonth: currentMonth, usedCount: 0 };
  }

  try {
    const parsed = JSON.parse(raw) as StoredUsage;
    if (parsed.yearMonth !== currentMonth) {
      return { yearMonth: currentMonth, usedCount: 0 };
    }
    return parsed;
  } catch {
    return { yearMonth: currentMonth, usedCount: 0 };
  }
}

function saveUsage(usage: StoredUsage): void {
  localStorage.setItem(WEB_SEARCH_USAGE_KEY, JSON.stringify(usage));
}

export function getWebSearchQuota(): WebSearchQuota {
  const usage = loadUsage();
  const monthlyLimit = DEFAULT_MONTHLY_LIMIT;
  const remainingCount = Math.max(monthlyLimit - usage.usedCount, 0);

  return {
    monthlyLimit,
    usedCount: usage.usedCount,
    remainingCount,
    resetDate: getResetDate(),
    isPremium: false,
  };
}

export function consumeWebSearchQuota(): WebSearchQuota {
  const usage = loadUsage();
  usage.usedCount += 1;
  saveUsage(usage);
  return getWebSearchQuota();
}
