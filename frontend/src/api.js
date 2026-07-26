import axios from "axios";

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL || "/api",
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => {
    if (response.config.responseType === "blob") {
      return response;
    }

    if (response.data && typeof response.data === "object" && "success" in response.data) {
      response.meta = response.data.meta;
      response.message = response.data.message;
      response.data = response.data.data ?? null;
    }

    return response;
  },
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem("token");
    }

    if (error.response?.data?.message) {
      error.response.data.detail = error.response.data.message;
      error.message = error.response.data.message;
    }

    return Promise.reject(error);
  },
);

export default api;
