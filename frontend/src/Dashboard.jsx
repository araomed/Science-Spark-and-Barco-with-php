import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "./api";

const today = () => new Date().toISOString().slice(0, 10);

const navItems = [
  { id: "dashboard", label: "Dashboard", icon: "D" },
  { id: "equipment", label: "Equipment", icon: "E" },
  { id: "customers", label: "Customers", icon: "C" },
  { id: "maintenance", label: "Maintenance", icon: "M" },
  { id: "alerts", label: "Alerts", icon: "A" },
  { id: "notifications", label: "Notifications", icon: "N" },
  { id: "serviceReports", label: "Service Reports", icon: "R" },
  { id: "serviceRequests", label: "Service Requests", icon: "Q" },
  { id: "documents", label: "Documents", icon: "F" },
  { id: "exports", label: "Reports", icon: "X" },
  { id: "activity", label: "Activity", icon: "L" },
];

const emptyData = {
  summary: null,
  statusCounts: [],
  recentReports: [],
  dashboardAlerts: { overdue: [], due_soon: [] },
  customers: [],
  instruments: [],
  maintenance: [],
  dueSoon: [],
  overdue: [],
  serviceReports: [],
  serviceRequests: [],
  documents: [],
  activity: [],
  notifications: [],
};

const getInitialForms = () => ({
  equipment: {
    name: "",
    model: "",
    serial_number: "",
    manufacturer: "",
    location: "",
    status: "active",
    purchase_date: "",
    customer_id: "",
  },
  customer: {
    name: "",
    contact_person: "",
    email: "",
    phone: "",
    address: "",
  },
  maintenance: {
    instrument_id: "",
    date: today(),
    type: "preventive",
    description: "",
    technician: "",
    next_due_date: "",
  },
  serviceReport: {
    instrument_id: "",
    date: today(),
    summary: "",
    technician: "",
  },
  serviceRequest: {
    instrument_id: "",
    customer_id: "",
    description: "",
    status: "open",
    assigned_technician: "",
    created_date: today(),
  },
  document: {
    title: "",
    category: "Manual",
    instrument_id: "",
    description: "",
  },
});

function cleanPayload(payload, numberFields = []) {
  return Object.fromEntries(
    Object.entries(payload).map(([key, value]) => {
      if (numberFields.includes(key)) {
        return [key, value === "" || value === null ? null : Number(value)];
      }
      return [key, value === "" ? null : value];
    }),
  );
}

function formatDate(value) {
  if (!value) return "Not set";
  return new Intl.DateTimeFormat("en", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  }).format(new Date(`${value}T00:00:00`));
}

function display(value) {
  return value === null || value === undefined || value === "" ? "Not set" : value;
}

function getApiIssue(error) {
  const status = error.response?.status;
  if (status === 401) return "Your session expired.";
  if (status === 403) return "Permission required.";
  if (status === 404) return "Endpoint not found.";
  return "Could not load.";
}

function getApiErrorMessage(error, fallback) {
  const detail = error.response?.data?.detail;
  if (typeof detail === "string") return detail;
  if (Array.isArray(detail)) {
    return detail.map((item) => item.msg || JSON.stringify(item)).join(" ");
  }
  if (detail && typeof detail === "object") {
    return JSON.stringify(detail);
  }
  return error.message || fallback;
}

function StatusChip({ value }) {
  const normalized = String(value || "unknown").toLowerCase();
  return <span className={`status-chip status-${normalized}`}>{display(value)}</span>;
}

function EmptyState({ title = "No records yet", detail = "Records will appear here when the API returns data." }) {
  return (
    <div className="empty-state">
      <strong>{title}</strong>
      <span>{detail}</span>
    </div>
  );
}

function SectionHeader({ title, eyebrow, actions }) {
  return (
    <div className="section-header">
      <div>
        <p className="eyebrow">{eyebrow}</p>
        <h2>{title}</h2>
      </div>
      {actions ? <div className="section-actions">{actions}</div> : null}
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label className="field compact-field">
      <span>{label}</span>
      {children}
    </label>
  );
}

function DataTable({ columns, rows, getKey, emptyTitle }) {
  if (!rows.length) {
    return <EmptyState title={emptyTitle} />;
  }

  return (
    <div className="table-shell">
      <table>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key}>{column.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={getKey(row)}>
              {columns.map((column) => (
                <td key={column.key}>{column.render ? column.render(row) : display(row[column.key])}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Dashboard() {
  const navigate = useNavigate();
  const [activeSection, setActiveSection] = useState("dashboard");
  const [data, setData] = useState(emptyData);
  const [forms, setForms] = useState(getInitialForms);
  const [documentFile, setDocumentFile] = useState(null);
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState("");
  const [notice, setNotice] = useState("");
  const [issues, setIssues] = useState({});
  const [equipmentFilter, setEquipmentFilter] = useState("");
  const [documentQuery, setDocumentQuery] = useState("");
  const [documentCategory, setDocumentCategory] = useState("");
  const [profile, setProfile] = useState(null);
  const [qrPreview, setQrPreview] = useState(null);
  const [editModal, setEditModal] = useState(null);

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 3200);
  };

  const loadAll = useCallback(async () => {
    setLoading(true);

    const requests = [
      ["me", api.get("/users/me")],
      ["summary", api.get("/dashboard/summary")],
      ["statusCounts", api.get("/dashboard/instruments-by-status")],
      ["recentReports", api.get("/dashboard/recent-service-reports")],
      ["dashboardAlerts", api.get("/dashboard/alerts")],
      ["customers", api.get("/customers")],
      ["instruments", api.get("/instruments")],
      ["maintenance", api.get("/maintenance")],
      ["dueSoon", api.get("/maintenance/alerts/due-soon")],
      ["overdue", api.get("/maintenance/alerts/overdue")],
      ["serviceReports", api.get("/service-reports")],
      ["serviceRequests", api.get("/service-requests")],
      ["documents", api.get("/documents")],
      ["activity", api.get("/activity-logs")],
      ["notifications", api.get("/notifications")],
    ];

    const results = await Promise.allSettled(requests.map(([, request]) => request));
    const nextData = { ...emptyData };
    const nextIssues = {};

    requests.forEach(([key], index) => {
      const result = results[index];
      if (result.status === "fulfilled") {
        if (key === "me") {
          setUser(result.value.data);
        } else {
          nextData[key] = result.value.data;
        }
      } else {
        nextIssues[key] = getApiIssue(result.reason);
        if (key === "me" && result.reason.response?.status === 401) {
          navigate("/login", { replace: true });
        }
      }
    });

    setData(nextData);
    setIssues(nextIssues);
    setLoading(false);
  }, [navigate]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  useEffect(() => {
    return () => {
      if (qrPreview?.url) {
        window.URL.revokeObjectURL(qrPreview.url);
      }
    };
  }, [qrPreview]);

  const instrumentById = useMemo(
    () => new Map(data.instruments.map((instrument) => [instrument.id, instrument])),
    [data.instruments],
  );

  const customerById = useMemo(
    () => new Map(data.customers.map((customer) => [customer.id, customer])),
    [data.customers],
  );

  const filteredInstruments = useMemo(() => {
    const term = equipmentFilter.trim().toLowerCase();
    if (!term) return data.instruments;
    return data.instruments.filter((item) =>
      [item.name, item.model, item.serial_number, item.manufacturer, item.location, item.status]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term)),
    );
  }, [data.instruments, equipmentFilter]);

  const updateForm = (formName, field, value) => {
    setForms((current) => ({
      ...current,
      [formName]: {
        ...current[formName],
        [field]: value,
      },
    }));
  };

  const resetForm = (formName) => {
    setForms((current) => ({
      ...current,
      [formName]: getInitialForms()[formName],
    }));
  };

  const getEditConfig = (type) => {
    const instrumentOptions = data.instruments.map((instrument) => ({
      label: instrument.name,
      value: instrument.id,
    }));
    const customerOptions = data.customers.map((customer) => ({
      label: customer.name,
      value: customer.id,
    }));

    return {
      equipment: {
        title: "Edit Equipment",
        endpoint: (id) => `/instruments/${id}`,
        numberFields: ["customer_id"],
        fields: [
          { name: "name", label: "Name", required: true },
          { name: "model", label: "Model" },
          { name: "serial_number", label: "Serial Number" },
          { name: "manufacturer", label: "Manufacturer" },
          { name: "location", label: "Location" },
          {
            name: "status",
            label: "Status",
            type: "select",
            options: [
              { label: "Active", value: "active" },
              { label: "Inactive", value: "inactive" },
              { label: "Maintenance", value: "maintenance" },
              { label: "Retired", value: "retired" },
            ],
          },
          { name: "purchase_date", label: "Purchase Date", type: "date" },
          { name: "customer_id", label: "Customer", type: "select", options: [{ label: "Unassigned", value: "" }, ...customerOptions] },
        ],
      },
      customer: {
        title: "Edit Customer",
        endpoint: (id) => `/customers/${id}`,
        numberFields: [],
        fields: [
          { name: "name", label: "Name", required: true },
          { name: "contact_person", label: "Contact Person" },
          { name: "email", label: "Email", type: "email" },
          { name: "phone", label: "Phone" },
          { name: "address", label: "Address" },
        ],
      },
      maintenance: {
        title: "Edit Maintenance Record",
        endpoint: (id) => `/maintenance/${id}`,
        numberFields: ["instrument_id"],
        fields: [
          { name: "instrument_id", label: "Equipment", type: "select", options: instrumentOptions, required: true },
          { name: "date", label: "Date", type: "date", required: true },
          {
            name: "type",
            label: "Type",
            type: "select",
            options: [
              { label: "Preventive", value: "preventive" },
              { label: "Corrective", value: "corrective" },
              { label: "Inspection", value: "inspection" },
              { label: "Calibration", value: "calibration" },
            ],
          },
          { name: "technician", label: "Technician" },
          { name: "next_due_date", label: "Next Due", type: "date" },
          { name: "description", label: "Description" },
        ],
      },
      serviceReport: {
        title: "Edit Service Report",
        endpoint: (id) => `/service-reports/${id}`,
        numberFields: ["instrument_id"],
        fields: [
          { name: "instrument_id", label: "Equipment", type: "select", options: instrumentOptions, required: true },
          { name: "date", label: "Date", type: "date", required: true },
          { name: "technician", label: "Technician" },
          { name: "summary", label: "Summary" },
        ],
      },
      serviceRequest: {
        title: "Edit Service Request",
        endpoint: (id) => `/service-requests/${id}`,
        numberFields: ["instrument_id", "customer_id"],
        fields: [
          { name: "instrument_id", label: "Equipment", type: "select", options: instrumentOptions, required: true },
          { name: "customer_id", label: "Customer", type: "select", options: customerOptions, required: true },
          { name: "assigned_technician", label: "Technician" },
          { name: "description", label: "Description", required: true },
        ],
      },
      document: {
        title: "Edit Document Metadata",
        endpoint: (id) => `/documents/${id}`,
        numberFields: ["instrument_id"],
        fields: [
          { name: "title", label: "Title", required: true },
          { name: "category", label: "Category", required: true },
          { name: "instrument_id", label: "Equipment", type: "select", options: [{ label: "Unlinked", value: "" }, ...instrumentOptions] },
          { name: "description", label: "Description" },
        ],
      },
    }[type];
  };

  const startEdit = (type, row) => {
    const config = getEditConfig(type);
    const values = {};
    config.fields.forEach((field) => {
      values[field.name] = row[field.name] ?? "";
    });
    setEditModal({ type, id: row.id, title: config.title, values });
  };

  const updateEditField = (field, value) => {
    setEditModal((current) => ({
      ...current,
      values: {
        ...current.values,
        [field]: value,
      },
    }));
  };

  const saveEdit = async (event) => {
    event.preventDefault();
    const config = getEditConfig(editModal.type);
    setSubmitting(`edit-${editModal.type}-${editModal.id}`);

    try {
      await api.put(config.endpoint(editModal.id), cleanPayload(editModal.values, config.numberFields));
      setEditModal(null);
      await loadAll();
      showNotice("Record updated.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "The record could not be updated."));
    } finally {
      setSubmitting("");
    }
  };

  const createRecord = async (event, formName, endpoint, numberFields, message) => {
    event.preventDefault();
    setSubmitting(formName);

    try {
      await api.post(endpoint, cleanPayload(forms[formName], numberFields));
      resetForm(formName);
      await loadAll();
      showNotice(message);
    } catch (error) {
      showNotice(getApiErrorMessage(error, "The record could not be saved."));
    } finally {
      setSubmitting("");
    }
  };

  const deleteRecord = async (endpoint, message) => {
    if (!window.confirm("Delete this record?")) return;
    setSubmitting(endpoint);

    try {
      await api.delete(endpoint);
      await loadAll();
      showNotice(message);
    } catch (error) {
      showNotice(getApiErrorMessage(error, "The record could not be deleted."));
    } finally {
      setSubmitting("");
    }
  };

  const updateRequestStatus = async (requestId, status) => {
    setSubmitting(`request-${requestId}`);
    try {
      await api.put(`/service-requests/${requestId}/status`, { status });
      await loadAll();
      showNotice("Service request status updated.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Status could not be updated."));
    } finally {
      setSubmitting("");
    }
  };

  const generateMaintenanceReminders = async () => {
    setSubmitting("notifications");
    try {
      const response = await api.post("/notifications/maintenance-reminders");
      await loadAll();
      showNotice(`${response.data.length} maintenance reminder${response.data.length === 1 ? "" : "s"} generated.`);
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Maintenance reminders could not be generated."));
    } finally {
      setSubmitting("");
    }
  };

  const markNotificationRead = async (notificationId) => {
    setSubmitting(`notification-${notificationId}`);
    try {
      await api.put(`/notifications/${notificationId}/read`);
      await loadAll();
      showNotice("Notification marked as read.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Notification could not be updated."));
    } finally {
      setSubmitting("");
    }
  };

  const markAllNotificationsRead = async () => {
    setSubmitting("notifications-read-all");
    try {
      await api.put("/notifications/read-all");
      await loadAll();
      showNotice("All notifications marked as read.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Notifications could not be updated."));
    } finally {
      setSubmitting("");
    }
  };

  const uploadDocument = async (event) => {
    event.preventDefault();
    if (!documentFile) {
      showNotice("Choose a file before uploading.");
      return;
    }

    const payload = new FormData();
    payload.append("title", forms.document.title);
    payload.append("category", forms.document.category);
    payload.append("description", forms.document.description);
    if (forms.document.instrument_id) {
      payload.append("instrument_id", forms.document.instrument_id);
    }
    payload.append("file", documentFile);

    setSubmitting("document");
    try {
      await api.post("/documents", payload, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      resetForm("document");
      setDocumentFile(null);
      event.target.reset();
      await loadAll();
      showNotice("Document uploaded.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Document upload failed."));
    } finally {
      setSubmitting("");
    }
  };

  const downloadBlob = async (endpoint, filename, params = {}) => {
    setSubmitting(endpoint);
    try {
      const response = await api.get(endpoint, { params, responseType: "blob" });
      const blobUrl = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement("a");
      link.href = blobUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(blobUrl);
      showNotice("Download started.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Download failed."));
    } finally {
      setSubmitting("");
    }
  };

  const openQrPreview = async (instrument) => {
    setSubmitting(`view-qr-${instrument.id}`);
    try {
      const response = await api.get(`/instruments/${instrument.id}/qrcode`, {
        responseType: "blob",
      });
      const imageUrl = window.URL.createObjectURL(response.data);
      setQrPreview({
        id: instrument.id,
        name: instrument.name || `Instrument #${instrument.id}`,
        url: imageUrl,
      });
    } catch (error) {
      if (error.response?.status === 404) {
        showNotice("This equipment could not be found.");
      } else {
        showNotice(getApiErrorMessage(error, "QR code could not be opened."));
      }
    } finally {
      setSubmitting("");
    }
  };

  const openProfile = async (instrumentId) => {
    setSubmitting(`profile-${instrumentId}`);
    try {
      const response = await api.get(`/instruments/${instrumentId}/profile`);
      setProfile(response.data);
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Profile could not be loaded."));
    } finally {
      setSubmitting("");
    }
  };

  const searchDocuments = async (event) => {
    event.preventDefault();
    setSubmitting("document-search");

    try {
      const endpoint = documentQuery.trim() ? "/documents/search" : "/documents/browse";
      const response = await api.get(endpoint, {
        params: {
          q: documentQuery.trim() || undefined,
          category: documentCategory || undefined,
        },
      });
      setData((current) => ({ ...current, documents: response.data }));
      showNotice("Documents refreshed.");
    } catch (error) {
      showNotice(getApiErrorMessage(error, "Document search failed."));
    } finally {
      setSubmitting("");
    }
  };

  const instrumentName = (id) => instrumentById.get(id)?.name || `Instrument #${id}`;
  const customerName = (id) => customerById.get(id)?.name || `Customer #${id}`;

  const renderDashboard = () => {
    const summary = data.summary;
    const statusTotal = data.statusCounts.reduce((total, item) => total + item.count, 0);

    return (
      <section className="content-section">
        <SectionHeader
          eyebrow="Command center"
          title="Operations Dashboard"
          actions={<button className="ghost-action" onClick={loadAll}>Refresh</button>}
        />

        {issues.summary ? <p className="notice-inline">{issues.summary}</p> : null}

        <div className="metric-grid">
          <article className="metric-card">
            <span>Total Equipment</span>
            <strong>{summary?.total_instruments ?? 0}</strong>
            <small>{summary?.active_instruments ?? 0} active</small>
          </article>
          <article className="metric-card accent-mint">
            <span>Customers</span>
            <strong>{summary?.total_customers ?? 0}</strong>
            <small>Linked to inventory</small>
          </article>
          <article className="metric-card accent-amber">
            <span>Due Soon</span>
            <strong>{summary?.due_soon_maintenance ?? 0}</strong>
            <small>Next 30 days</small>
          </article>
          <article className="metric-card accent-rose">
            <span>Overdue</span>
            <strong>{summary?.overdue_maintenance ?? 0}</strong>
            <small>Needs attention</small>
          </article>
          <article className="metric-card accent-blue">
            <span>Reports This Month</span>
            <strong>{summary?.service_reports_this_month ?? 0}</strong>
            <small>Generated PDFs</small>
          </article>
        </div>

        <div className="dashboard-grid">
          <section className="panel">
            <h3>Equipment Status</h3>
            {data.statusCounts.length ? (
              <div className="status-bars">
                {data.statusCounts.map((item) => (
                  <div className="status-bar-row" key={item.status}>
                    <div>
                      <span>{display(item.status)}</span>
                      <strong>{item.count}</strong>
                    </div>
                    <div className="bar-track">
                      <span style={{ width: `${statusTotal ? (item.count / statusTotal) * 100 : 0}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState title="No status data" />
            )}
          </section>

          <section className="panel">
            <h3>Maintenance Attention</h3>
            <div className="alert-list">
              {[...data.overdue.slice(0, 4), ...data.dueSoon.slice(0, 4)].slice(0, 6).map((record) => (
                <div className="alert-item" key={`${record.id}-${record.next_due_date}`}>
                  <div>
                    <strong>{instrumentName(record.instrument_id)}</strong>
                    <span>{record.type} maintenance</span>
                  </div>
                  <StatusChip value={record.next_due_date && record.next_due_date < today() ? "overdue" : "due soon"} />
                </div>
              ))}
              {!data.overdue.length && !data.dueSoon.length ? <EmptyState title="No active alerts" /> : null}
            </div>
          </section>
        </div>

        <section className="panel">
          <h3>Recent Service Reports</h3>
          <DataTable
            columns={[
              { key: "id", label: "ID" },
              { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
              { key: "date", label: "Date", render: (row) => formatDate(row.date) },
              { key: "technician", label: "Technician" },
              { key: "summary", label: "Summary" },
            ]}
            emptyTitle="No recent reports"
            getKey={(row) => row.id}
            rows={data.recentReports}
          />
        </section>
      </section>
    );
  };

  const renderEquipment = () => (
    <section className="content-section">
      <SectionHeader
        eyebrow="Inventory"
        title="Equipment"
        actions={
          <input
            className="toolbar-search"
            onChange={(event) => setEquipmentFilter(event.target.value)}
            placeholder="Search equipment"
            type="search"
            value={equipmentFilter}
          />
        }
      />

      <form className="form-grid" onSubmit={(event) => createRecord(event, "equipment", "/instruments", ["customer_id"], "Equipment created.")}>
        <Field label="Name"><input onChange={(event) => updateForm("equipment", "name", event.target.value)} required value={forms.equipment.name} /></Field>
        <Field label="Model"><input onChange={(event) => updateForm("equipment", "model", event.target.value)} value={forms.equipment.model} /></Field>
        <Field label="Serial Number"><input onChange={(event) => updateForm("equipment", "serial_number", event.target.value)} value={forms.equipment.serial_number} /></Field>
        <Field label="Manufacturer"><input onChange={(event) => updateForm("equipment", "manufacturer", event.target.value)} value={forms.equipment.manufacturer} /></Field>
        <Field label="Location"><input onChange={(event) => updateForm("equipment", "location", event.target.value)} value={forms.equipment.location} /></Field>
        <Field label="Status">
          <select onChange={(event) => updateForm("equipment", "status", event.target.value)} value={forms.equipment.status}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="maintenance">Maintenance</option>
            <option value="retired">Retired</option>
          </select>
        </Field>
        <Field label="Purchase Date"><input onChange={(event) => updateForm("equipment", "purchase_date", event.target.value)} type="date" value={forms.equipment.purchase_date} /></Field>
        <Field label="Customer">
          <select onChange={(event) => updateForm("equipment", "customer_id", event.target.value)} value={forms.equipment.customer_id}>
            <option value="">Unassigned</option>
            {data.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
          </select>
        </Field>
        <button className="primary-action form-submit" disabled={submitting === "equipment"} type="submit">Add Equipment</button>
      </form>

      <DataTable
        columns={[
          { key: "name", label: "Name" },
          { key: "model", label: "Model" },
          { key: "serial_number", label: "Serial" },
          { key: "status", label: "Status", render: (row) => <StatusChip value={row.status} /> },
          { key: "customer_id", label: "Customer", render: (row) => row.customer_id ? customerName(row.customer_id) : "Unassigned" },
          { key: "location", label: "Location" },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" title="Edit equipment" onClick={() => startEdit("equipment", row)}>Edit</button>
                <button className="icon-action" title="Open profile" onClick={() => openProfile(row.id)}>Profile</button>
                <button className="icon-action" title="View QR code" onClick={() => openQrPreview(row)}>View QR</button>
                <button className="danger-action" title="Delete equipment" onClick={() => deleteRecord(`/instruments/${row.id}`, "Equipment deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No equipment found"
        getKey={(row) => row.id}
        rows={filteredInstruments}
      />
    </section>
  );

  const renderCustomers = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Accounts" title="Customers" />
      <form className="form-grid" onSubmit={(event) => createRecord(event, "customer", "/customers", [], "Customer created.")}>
        <Field label="Name"><input onChange={(event) => updateForm("customer", "name", event.target.value)} required value={forms.customer.name} /></Field>
        <Field label="Contact Person"><input onChange={(event) => updateForm("customer", "contact_person", event.target.value)} value={forms.customer.contact_person} /></Field>
        <Field label="Email"><input onChange={(event) => updateForm("customer", "email", event.target.value)} type="email" value={forms.customer.email} /></Field>
        <Field label="Phone"><input onChange={(event) => updateForm("customer", "phone", event.target.value)} value={forms.customer.phone} /></Field>
        <Field label="Address"><input onChange={(event) => updateForm("customer", "address", event.target.value)} value={forms.customer.address} /></Field>
        <button className="primary-action form-submit" disabled={submitting === "customer"} type="submit">Add Customer</button>
      </form>

      <DataTable
        columns={[
          { key: "name", label: "Name" },
          { key: "contact_person", label: "Contact" },
          { key: "email", label: "Email" },
          { key: "phone", label: "Phone" },
          { key: "address", label: "Address" },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => startEdit("customer", row)}>Edit</button>
                <button className="danger-action" onClick={() => deleteRecord(`/customers/${row.id}`, "Customer deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No customers found"
        getKey={(row) => row.id}
        rows={data.customers}
      />
    </section>
  );

  const renderMaintenance = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Scheduling" title="Maintenance" />
      <form className="form-grid" onSubmit={(event) => createRecord(event, "maintenance", "/maintenance", ["instrument_id"], "Maintenance record created.")}>
        <Field label="Equipment">
          <select onChange={(event) => updateForm("maintenance", "instrument_id", event.target.value)} required value={forms.maintenance.instrument_id}>
            <option value="">Select equipment</option>
            {data.instruments.map((instrument) => <option key={instrument.id} value={instrument.id}>{instrument.name}</option>)}
          </select>
        </Field>
        <Field label="Date"><input onChange={(event) => updateForm("maintenance", "date", event.target.value)} required type="date" value={forms.maintenance.date} /></Field>
        <Field label="Type">
          <select onChange={(event) => updateForm("maintenance", "type", event.target.value)} value={forms.maintenance.type}>
            <option value="preventive">Preventive</option>
            <option value="corrective">Corrective</option>
            <option value="inspection">Inspection</option>
            <option value="calibration">Calibration</option>
          </select>
        </Field>
        <Field label="Technician"><input onChange={(event) => updateForm("maintenance", "technician", event.target.value)} value={forms.maintenance.technician} /></Field>
        <Field label="Next Due"><input onChange={(event) => updateForm("maintenance", "next_due_date", event.target.value)} type="date" value={forms.maintenance.next_due_date} /></Field>
        <Field label="Description"><input onChange={(event) => updateForm("maintenance", "description", event.target.value)} value={forms.maintenance.description} /></Field>
        <button className="primary-action form-submit" disabled={submitting === "maintenance"} type="submit">Add Record</button>
      </form>

      <DataTable
        columns={[
          { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
          { key: "date", label: "Date", render: (row) => formatDate(row.date) },
          { key: "type", label: "Type" },
          { key: "technician", label: "Technician" },
          { key: "next_due_date", label: "Next Due", render: (row) => formatDate(row.next_due_date) },
          { key: "description", label: "Description" },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => startEdit("maintenance", row)}>Edit</button>
                <button className="danger-action" onClick={() => deleteRecord(`/maintenance/${row.id}`, "Maintenance record deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No maintenance records found"
        getKey={(row) => row.id}
        rows={data.maintenance}
      />
    </section>
  );

  const renderAlerts = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Maintenance" title="Alerts" />
      <div className="dashboard-grid">
        <section className="panel">
          <h3>Overdue</h3>
          <DataTable
            columns={[
              { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
              { key: "type", label: "Type" },
              { key: "technician", label: "Technician" },
              { key: "next_due_date", label: "Due Date", render: (row) => formatDate(row.next_due_date) },
            ]}
            emptyTitle="No overdue maintenance"
            getKey={(row) => row.id}
            rows={data.overdue}
          />
        </section>
        <section className="panel">
          <h3>Due Soon</h3>
          <DataTable
            columns={[
              { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
              { key: "type", label: "Type" },
              { key: "technician", label: "Technician" },
              { key: "next_due_date", label: "Due Date", render: (row) => formatDate(row.next_due_date) },
            ]}
            emptyTitle="No upcoming maintenance"
            getKey={(row) => row.id}
            rows={data.dueSoon}
          />
        </section>
      </div>
    </section>
  );

  const renderNotifications = () => (
    <section className="content-section">
      <SectionHeader
        eyebrow="Reminders"
        title="Notifications"
        actions={
          <>
            <button className="primary-action" disabled={submitting === "notifications"} onClick={generateMaintenanceReminders}>
              Generate Reminders
            </button>
            <button className="ghost-action" disabled={submitting === "notifications-read-all"} onClick={markAllNotificationsRead}>
              Mark All Read
            </button>
          </>
        }
      />
      {issues.notifications ? <p className="notice-inline">{issues.notifications}</p> : null}
      <DataTable
        columns={[
          { key: "severity", label: "Severity", render: (row) => <StatusChip value={row.severity} /> },
          { key: "title", label: "Title" },
          { key: "message", label: "Message" },
          { key: "created_at", label: "Created", render: (row) => new Date(row.created_at).toLocaleString() },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => markNotificationRead(row.id)}>Mark Read</button>
                <button className="danger-action" onClick={() => deleteRecord(`/notifications/${row.id}`, "Notification deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No unread notifications"
        getKey={(row) => row.id}
        rows={data.notifications}
      />
    </section>
  );

  const renderServiceReports = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Field service" title="Service Reports" />
      <form className="form-grid" onSubmit={(event) => createRecord(event, "serviceReport", "/service-reports", ["instrument_id"], "Service report created.")}>
        <Field label="Equipment">
          <select onChange={(event) => updateForm("serviceReport", "instrument_id", event.target.value)} required value={forms.serviceReport.instrument_id}>
            <option value="">Select equipment</option>
            {data.instruments.map((instrument) => <option key={instrument.id} value={instrument.id}>{instrument.name}</option>)}
          </select>
        </Field>
        <Field label="Date"><input onChange={(event) => updateForm("serviceReport", "date", event.target.value)} required type="date" value={forms.serviceReport.date} /></Field>
        <Field label="Technician"><input onChange={(event) => updateForm("serviceReport", "technician", event.target.value)} value={forms.serviceReport.technician} /></Field>
        <Field label="Summary"><input onChange={(event) => updateForm("serviceReport", "summary", event.target.value)} value={forms.serviceReport.summary} /></Field>
        <button className="primary-action form-submit" disabled={submitting === "serviceReport"} type="submit">Create Report</button>
      </form>

      <DataTable
        columns={[
          { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
          { key: "date", label: "Date", render: (row) => formatDate(row.date) },
          { key: "technician", label: "Technician" },
          { key: "summary", label: "Summary" },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => startEdit("serviceReport", row)}>Edit</button>
                <button className="icon-action" onClick={() => downloadBlob(`/service-reports/${row.id}/download`, `service_report_${row.id}.pdf`)}>PDF</button>
                <button className="danger-action" onClick={() => deleteRecord(`/service-reports/${row.id}`, "Service report deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No service reports found"
        getKey={(row) => row.id}
        rows={data.serviceReports}
      />
    </section>
  );

  const renderServiceRequests = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Requests" title="Service Requests" />
      <form className="form-grid" onSubmit={(event) => createRecord(event, "serviceRequest", "/service-requests", ["instrument_id", "customer_id"], "Service request created.")}>
        <Field label="Equipment">
          <select onChange={(event) => updateForm("serviceRequest", "instrument_id", event.target.value)} required value={forms.serviceRequest.instrument_id}>
            <option value="">Select equipment</option>
            {data.instruments.map((instrument) => <option key={instrument.id} value={instrument.id}>{instrument.name}</option>)}
          </select>
        </Field>
        <Field label="Customer">
          <select onChange={(event) => updateForm("serviceRequest", "customer_id", event.target.value)} required value={forms.serviceRequest.customer_id}>
            <option value="">Select customer</option>
            {data.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
          </select>
        </Field>
        <Field label="Technician"><input onChange={(event) => updateForm("serviceRequest", "assigned_technician", event.target.value)} value={forms.serviceRequest.assigned_technician} /></Field>
        <Field label="Description"><input onChange={(event) => updateForm("serviceRequest", "description", event.target.value)} required value={forms.serviceRequest.description} /></Field>
        <button className="primary-action form-submit" disabled={submitting === "serviceRequest"} type="submit">Open Request</button>
      </form>

      <DataTable
        columns={[
          { key: "instrument_id", label: "Equipment", render: (row) => instrumentName(row.instrument_id) },
          { key: "customer_id", label: "Customer", render: (row) => customerName(row.customer_id) },
          {
            key: "status",
            label: "Status",
            render: (row) => (
              <select className="status-select" onChange={(event) => updateRequestStatus(row.id, event.target.value)} value={row.status}>
                <option value="open">Open</option>
                <option value="in_progress">In Progress</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
              </select>
            ),
          },
          { key: "assigned_technician", label: "Technician" },
          { key: "created_date", label: "Created", render: (row) => formatDate(row.created_date) },
          { key: "description", label: "Description" },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => startEdit("serviceRequest", row)}>Edit</button>
                <button className="danger-action" onClick={() => deleteRecord(`/service-requests/${row.id}`, "Service request deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No service requests found"
        getKey={(row) => row.id}
        rows={data.serviceRequests}
      />
    </section>
  );

  const renderDocuments = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Knowledge base" title="Documents" />
      <form className="form-grid" onSubmit={uploadDocument}>
        <Field label="Title"><input onChange={(event) => updateForm("document", "title", event.target.value)} required value={forms.document.title} /></Field>
        <Field label="Category"><input onChange={(event) => updateForm("document", "category", event.target.value)} required value={forms.document.category} /></Field>
        <Field label="Equipment">
          <select onChange={(event) => updateForm("document", "instrument_id", event.target.value)} value={forms.document.instrument_id}>
            <option value="">Unlinked</option>
            {data.instruments.map((instrument) => <option key={instrument.id} value={instrument.id}>{instrument.name}</option>)}
          </select>
        </Field>
        <Field label="Description"><input onChange={(event) => updateForm("document", "description", event.target.value)} value={forms.document.description} /></Field>
        <Field label="File"><input onChange={(event) => setDocumentFile(event.target.files?.[0] || null)} required type="file" /></Field>
        <button className="primary-action form-submit" disabled={submitting === "document"} type="submit">Upload</button>
      </form>

      <form className="toolbar-form" onSubmit={searchDocuments}>
        <input onChange={(event) => setDocumentQuery(event.target.value)} placeholder="Search documents" type="search" value={documentQuery} />
        <input onChange={(event) => setDocumentCategory(event.target.value)} placeholder="Category" value={documentCategory} />
        <button className="ghost-action" disabled={submitting === "document-search"} type="submit">Search</button>
        <button className="ghost-action" onClick={() => { setDocumentQuery(""); setDocumentCategory(""); loadAll(); }} type="button">Reset</button>
      </form>

      <DataTable
        columns={[
          { key: "title", label: "Title" },
          { key: "category", label: "Category" },
          { key: "instrument_id", label: "Equipment", render: (row) => row.instrument_id ? instrumentName(row.instrument_id) : "Unlinked" },
          { key: "uploaded_by", label: "Uploaded By" },
          { key: "upload_date", label: "Uploaded", render: (row) => formatDate(row.upload_date) },
          {
            key: "actions",
            label: "Actions",
            render: (row) => (
              <div className="row-actions">
                <button className="icon-action" onClick={() => startEdit("document", row)}>Edit</button>
                <button className="icon-action" onClick={() => downloadBlob(`/documents/${row.id}/download`, `${row.title || "document"}`)}>Download</button>
                <button className="danger-action" onClick={() => deleteRecord(`/documents/${row.id}`, "Document deleted.")}>Delete</button>
              </div>
            ),
          },
        ]}
        emptyTitle="No documents found"
        getKey={(row) => row.id}
        rows={data.documents}
      />
    </section>
  );

  const renderExports = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Exports" title="Reports" />
      <div className="export-grid">
        {[
          ["Equipment", "/reports/instruments/export", "instruments_export"],
          ["Maintenance", "/reports/maintenance/export", "maintenance_export"],
          ["Service Reports", "/reports/service-reports/export", "service_reports_export"],
        ].map(([label, endpoint, filename]) => (
          <section className="panel export-panel" key={endpoint}>
            <h3>{label}</h3>
            <div className="row-actions">
              <button className="primary-action" onClick={() => downloadBlob(endpoint, `${filename}.csv`, { format: "csv" })}>CSV</button>
              <button className="ghost-action" onClick={() => downloadBlob(endpoint, `${filename}.xlsx`, { format: "excel" })}>Excel</button>
            </div>
          </section>
        ))}
      </div>
      {issues.activity === "Permission required." ? <p className="notice-inline">Exports may require manager or admin access.</p> : null}
    </section>
  );

  const renderActivity = () => (
    <section className="content-section">
      <SectionHeader eyebrow="Audit" title="Activity Log" />
      {issues.activity ? <p className="notice-inline">{issues.activity}</p> : null}
      <DataTable
        columns={[
          { key: "timestamp", label: "Time", render: (row) => new Date(row.timestamp).toLocaleString() },
          { key: "username", label: "User" },
          { key: "action", label: "Action" },
          { key: "entity_type", label: "Entity" },
          { key: "entity_id", label: "Entity ID" },
          { key: "details", label: "Details" },
        ]}
        emptyTitle="No activity available"
        getKey={(row) => row.id}
        rows={data.activity}
      />
    </section>
  );

  const sectionMap = {
    dashboard: renderDashboard,
    equipment: renderEquipment,
    customers: renderCustomers,
    maintenance: renderMaintenance,
    alerts: renderAlerts,
    notifications: renderNotifications,
    serviceReports: renderServiceReports,
    serviceRequests: renderServiceRequests,
    documents: renderDocuments,
    exports: renderExports,
    activity: renderActivity,
  };

  const logout = () => {
    localStorage.removeItem("token");
    navigate("/login", { replace: true });
  };

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="sidebar-brand">
          <div className="brand-mark" aria-hidden="true">SS</div>
          <div>
            <strong>Science Spark</strong>
            <span>Lab System</span>
          </div>
        </div>

        <nav aria-label="Main navigation">
          {navItems.map((item) => (
            <button
              className={activeSection === item.id ? "nav-item active" : "nav-item"}
              key={item.id}
              onClick={() => setActiveSection(item.id)}
              type="button"
            >
              <span aria-hidden="true">{item.icon}</span>
              {item.label}
            </button>
          ))}
        </nav>
      </aside>

      <main className="workspace">
        <header className="topbar">
          <div>
            <p className="eyebrow">Workspace</p>
            <h1>{navItems.find((item) => item.id === activeSection)?.label || "Dashboard"}</h1>
          </div>
          <div className="user-menu">
            <span className="api-pill" title="This frontend is connected to the PHP backend on port 8080">
              PHP API
            </span>
            <div>
              <strong>{user?.username || "Signed in"}</strong>
              <span>{user?.role || "user"}</span>
            </div>
            <button className="ghost-action" onClick={logout}>Sign out</button>
          </div>
        </header>

        {notice ? <div className="toast" role="status">{notice}</div> : null}
        {loading ? <div className="loading-panel">Loading workspace...</div> : sectionMap[activeSection]()}
      </main>

      {profile ? (
        <div className="drawer-backdrop" onClick={() => setProfile(null)}>
          <aside className="profile-drawer" onClick={(event) => event.stopPropagation()}>
            <div className="drawer-header">
              <div>
                <p className="eyebrow">Equipment profile</p>
                <h2>{profile.instrument.name}</h2>
              </div>
              <button className="ghost-action" onClick={() => setProfile(null)}>Close</button>
            </div>
            <div className="profile-grid">
              <div><span>Status</span><StatusChip value={profile.instrument.status} /></div>
              <div><span>Model</span><strong>{display(profile.instrument.model)}</strong></div>
              <div><span>Serial</span><strong>{display(profile.instrument.serial_number)}</strong></div>
              <div><span>Location</span><strong>{display(profile.instrument.location)}</strong></div>
            </div>
            <div className="drawer-list">
              <h3>Maintenance</h3>
              {profile.maintenance_records.slice(0, 5).map((record) => (
                <p key={record.id}>{formatDate(record.date)} - {record.type}</p>
              ))}
              {!profile.maintenance_records.length ? <span>No maintenance records</span> : null}
            </div>
            <div className="drawer-list">
              <h3>Service Reports</h3>
              {profile.service_reports.slice(0, 5).map((report) => (
                <p key={report.id}>{formatDate(report.date)} - {display(report.technician)}</p>
              ))}
              {!profile.service_reports.length ? <span>No service reports</span> : null}
            </div>
            <div className="drawer-list">
              <h3>Documents</h3>
              {profile.documents.slice(0, 5).map((document) => (
                <p key={document.id}>{document.title}</p>
              ))}
              {!profile.documents.length ? <span>No linked documents</span> : null}
            </div>
          </aside>
        </div>
      ) : null}

      {editModal ? (
        <div className="drawer-backdrop qr-backdrop" onClick={() => setEditModal(null)}>
          <section className="edit-modal" onClick={(event) => event.stopPropagation()}>
            <div className="drawer-header">
              <div>
                <p className="eyebrow">Edit record</p>
                <h2>{editModal.title}</h2>
              </div>
              <button className="ghost-action" onClick={() => setEditModal(null)}>Close</button>
            </div>
            <form className="edit-form" onSubmit={saveEdit}>
              {getEditConfig(editModal.type).fields.map((field) => (
                <Field key={field.name} label={field.label}>
                  {field.type === "select" ? (
                    <select
                      onChange={(event) => updateEditField(field.name, event.target.value)}
                      required={field.required}
                      value={editModal.values[field.name]}
                    >
                      {!field.required ? null : <option value="">Select {field.label.toLowerCase()}</option>}
                      {field.options.map((option) => (
                        <option key={`${field.name}-${option.value}`} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  ) : (
                    <input
                      onChange={(event) => updateEditField(field.name, event.target.value)}
                      required={field.required}
                      type={field.type || "text"}
                      value={editModal.values[field.name]}
                    />
                  )}
                </Field>
              ))}
              <div className="row-actions">
                <button className="primary-action" disabled={submitting.startsWith("edit-")} type="submit">Save Changes</button>
                <button className="ghost-action" onClick={() => setEditModal(null)} type="button">Cancel</button>
              </div>
            </form>
          </section>
        </div>
      ) : null}

      {qrPreview ? (
        <div className="drawer-backdrop qr-backdrop" onClick={() => setQrPreview(null)}>
          <section className="qr-modal" onClick={(event) => event.stopPropagation()}>
            <div className="drawer-header">
              <div>
                <p className="eyebrow">QR code</p>
                <h2>{qrPreview.name}</h2>
              </div>
              <button className="ghost-action" onClick={() => setQrPreview(null)}>Close</button>
            </div>
            <div className="qr-preview-frame">
              <img alt={`QR code for ${qrPreview.name}`} src={qrPreview.url} />
            </div>
            <div className="row-actions">
              <button
                className="ghost-action"
                onClick={() => {
                  const instrumentId = qrPreview.id;
                  setQrPreview(null);
                  openProfile(instrumentId);
                }}
              >
                Open Profile
              </button>
            </div>
          </section>
        </div>
      ) : null}
    </div>
  );
}

export default Dashboard;
