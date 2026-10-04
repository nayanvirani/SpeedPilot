import React, { createContext, useContext } from 'react';

// Free-tier gate used across every page that offers an action (auto-fix,
// manual-fix code, app/script management, monitoring setup) - the backend
// is the real enforcement (every such endpoint checks PlanPolicy::
// hasPaidPlan() itself), this just keeps the UI from showing buttons that
// would only ever 403.
const PlanContext = createContext({ plan: null, isFree: true, loading: true });

export function PlanProvider({ value, children }) {
    return <PlanContext.Provider value={value}>{children}</PlanContext.Provider>;
}

export function usePlan() {
    return useContext(PlanContext);
}
