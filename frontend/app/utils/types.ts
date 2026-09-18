export interface AuditIssue {
  id: number;
  category: 'image' | 'js' | 'css' | 'theme' | 'third_party';
  severity: 'high' | 'medium' | 'low';
  title: string;
  description: string | null;
  fix_available: boolean;
  risk_tier: 'safe' | 'medium' | 'high';
}

export interface AppImpact {
  id: number;
  app_name: string;
  script_url: string | null;
  requests: number;
  size_bytes: number;
  estimated_blocking_ms: number | null;
  impact_level: 'high' | 'medium' | 'low';
  status: 'active' | 'disabled' | 'delayed' | 'excluded';
}

export interface Audit {
  id: number;
  score: number | null;
  lcp: number | null;
  inp: number | null;
  cls: number | null;
  fcp: number | null;
  ttfb: number | null;
  page_weight_bytes: number | null;
  js_weight_bytes: number | null;
  css_weight_bytes: number | null;
  status: 'pending' | 'running' | 'complete' | 'failed';
  url: string | null;
  created_at: string;
  issues?: AuditIssue[];
  app_impacts?: AppImpact[];
}

export interface Optimization {
  id: number;
  type: string;
  risk_tier: 'safe' | 'medium' | 'high';
  status: 'recommended' | 'applied' | 'rolled_back';
  applied_at: string | null;
  asset_key: string | null;
}

export interface MonitoringRun {
  id: number;
  run_at: string;
  trend_delta: number | null;
  audit: { id: number; score: number | null; created_at: string };
}
